<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ErrorLogger;
use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Diagnóstico e ações de manutenção do GET /admin/manutencao: espaço em disco, memória do
 * container, PHP/OPcache, limpeza de caches/logs/backups e o modo manutenção.
 *
 * Só mexe dentro de storage/ — reiniciar container ou matar query de aluno fica de fora de
 * propósito (precisaria do socket do Docker / CONNECTION_ADMIN, ver mysql/init/01-grants.sql);
 * pra isso o painel mostra o comando a rodar no servidor (HealthStatus::$hint).
 */
final class Maintenance
{
    /** Caminhos que continuam respondendo com o modo manutenção ligado (o admin precisa conseguir entrar). */
    private const ALLOWED_PATHS = ['/login', '/logout', '/csrf-token'];

    /** Acima disso o memory.limit_in_bytes do cgroup v1 significa "sem limite". */
    private const CGROUP_V1_UNLIMITED = 1 << 60;

    private static ?string $storage = null;

    /** Só pros testes: aponta pra um storage/ temporário. */
    public static function useStorage(?string $dir): void
    {
        self::$storage = $dir;
    }

    public static function storage(): string
    {
        return self::$storage ?? dirname(__DIR__, 2) . '/storage';
    }

    // ---- Modo manutenção ----------------------------------------------------------------

    /**
     * Fica em storage/cache, fora dos volumes de propósito: recriar o container (deploy,
     * docker compose up --force-recreate) desliga o modo sozinho em vez de deixar o lab
     * fechado por esquecimento.
     */
    private static function flagFile(): string
    {
        return self::storage() . '/cache/maintenance.json';
    }

    /** @return ?array{since: string, by: string, message: string} null = modo desligado. */
    public static function mode(): ?array
    {
        if (!is_file(self::flagFile())) {
            return null;
        }
        $row = json_decode((string) @file_get_contents(self::flagFile()), true);

        return [
            'since' => (string) ($row['since'] ?? ''),
            'by' => (string) ($row['by'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
        ];
    }

    public static function isOn(): bool
    {
        return is_file(self::flagFile());
    }

    public static function enable(string $by, string $message): bool
    {
        $dir = dirname(self::flagFile());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }

        return @file_put_contents(self::flagFile(), json_encode([
            'since' => (new DateTimeImmutable())->format(DATE_ATOM),
            'by' => $by,
            'message' => mb_substr(trim($message), 0, 300),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    public static function disable(): void
    {
        @unlink(self::flagFile());
    }

    /** Com o modo ligado, só admin passa — e qualquer um alcança login/logout/token CSRF. */
    public static function allows(string $path, bool $isAdmin): bool
    {
        return $isAdmin || in_array(rtrim($path, '/') ?: '/', self::ALLOWED_PATHS, true);
    }

    // ---- Limpezas -------------------------------------------------------------------------

    /**
     * Esvazia o cache dos templates compilados do Twig (storage/twig-cache) — ele se recria
     * sozinho na próxima página. Útil se um template ficou servindo versão velha/corrompida.
     *
     * Troca a pasta por uma vazia (rename é atômico) e só então apaga a antiga: apagar no
     * lugar fazia rmdir de subpastas enquanto outras requisições compilavam nelas, e o Twig
     * respondia "Unable to write in the cache directory" (ver ResilientTwigCache).
     *
     * @return array{files: int, bytes: int}
     */
    public static function clearTwigCache(): array
    {
        $dir = self::storage() . '/twig-cache';
        $old = $dir . '.old-' . bin2hex(random_bytes(4));
        if (!is_dir($dir) || !@rename($dir, $old)) {
            return self::emptyDirectory($dir);
        }
        @mkdir($dir, 0775);

        $removed = self::emptyDirectory($old);
        @rmdir($old);

        return $removed;
    }

    /** opcache_reset() vale só pro pool do PHP-FPM que atendeu a requisição — que, aqui, é o único. */
    public static function resetOpcache(): bool
    {
        return function_exists('opcache_reset') && self::opcache()['enabled'] && opcache_reset();
    }

    /**
     * Apaga logs de erro (storage/logs/app-AAAA-MM-DD.log) com mais de $days dias. O de hoje
     * nunca sai, mesmo com $days = 0 — é nele que o próximo erro vai cair.
     *
     * @return array{files: int, bytes: int}
     */
    public static function pruneLogs(int $days, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $limit = $now->modify('-' . max(0, $days) . ' days')->format('Y-m-d');
        $today = $now->format('Y-m-d');

        $removed = ['files' => 0, 'bytes' => 0];
        foreach (ErrorLogger::files() as $date => $file) {
            if ($date < $limit && $date !== $today) {
                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $removed['files']++;
                    $removed['bytes'] += $size;
                }
            }
        }

        return $removed;
    }

    /**
     * Mantém só os $keep backups mais recentes.
     *
     * @return array{files: int, bytes: int}
     */
    public static function pruneBackups(int $keep): array
    {
        $removed = ['files' => 0, 'bytes' => 0];
        foreach (array_slice(BackupService::all(), max(0, $keep)) as $backup) {
            if (BackupService::delete($backup['name'])) {
                $removed['files']++;
                $removed['bytes'] += $backup['size'];
            }
        }

        return $removed;
    }

    // ---- Diagnóstico ----------------------------------------------------------------------

    /** @return ?array{total: float, free: float, used: float, freeRatio: float} null = sem leitura. */
    public static function disk(): ?array
    {
        $free = @disk_free_space(self::storage());
        $total = @disk_total_space(self::storage());
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return ['total' => $total, 'free' => $free, 'used' => $total - $free, 'freeRatio' => $free / $total];
    }

    /** Quanto cada pasta de storage/ ocupa. @return array<string, int> nome => bytes */
    public static function storageUsage(): array
    {
        $usage = [];
        foreach (['logs', 'backups', 'twig-cache', 'cache'] as $dir) {
            $usage[$dir] = self::directorySize(self::storage() . '/' . $dir);
        }

        return $usage;
    }

    /**
     * Memória do container, lida do cgroup (é o que o `deploy.resources.limits.memory` do
     * compose limita). Tenta v2 (memory.current/memory.max) e cai pro v1
     * (memory/memory.usage_in_bytes) — o servidor de produção (Ubuntu 20.04, kernel 5.4) ainda
     * é v1. Fora do Docker devolve null.
     *
     * @return ?array{used: int, limit: ?int}
     */
    public static function containerMemory(string $cgroupDir = '/sys/fs/cgroup'): ?array
    {
        $current = self::readFile($cgroupDir . '/memory.current');
        if ($current !== null) {
            $max = trim((string) self::readFile($cgroupDir . '/memory.max'));

            // "max" = sem limite.
            return ['used' => (int) trim($current), 'limit' => ctype_digit($max) ? (int) $max : null];
        }

        $current = self::readFile($cgroupDir . '/memory/memory.usage_in_bytes');
        if ($current === null) {
            return null;
        }
        $max = trim((string) self::readFile($cgroupDir . '/memory/memory.limit_in_bytes'));

        return [
            'used' => (int) trim($current),
            // Sem limite, o v1 informa um número absurdo (~8 EiB, PAGE_COUNTER_MAX).
            'limit' => ctype_digit($max) && (int) $max < self::CGROUP_V1_UNLIMITED ? (int) $max : null,
        ];
    }

    /** @return ?array{0: float, 1: float, 2: float} média de carga em 1/5/15 min. */
    public static function loadAverage(): ?array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return $load === false ? null : [$load[0], $load[1], $load[2]];
    }

    /**
     * Limite de CPU do container: cgroup v2 `cpu.max` ("50000 100000" = 0,5 CPU) ou v1
     * `cpu.cfs_quota_us`/`cpu.cfs_period_us` (quota -1 = sem limite). null = sem limite/sem leitura.
     */
    public static function containerCpuLimit(string $cgroupDir = '/sys/fs/cgroup'): ?float
    {
        $raw = self::readFile($cgroupDir . '/cpu.max');
        if ($raw !== null) {
            if (preg_match('/^(\d+)\s+(\d+)/', trim($raw), $m) !== 1 || (int) $m[2] === 0) {
                return null;
            }

            return (int) $m[1] / (int) $m[2];
        }

        $quota = (int) trim((string) self::readFile($cgroupDir . '/cpu/cpu.cfs_quota_us'));
        $period = (int) trim((string) self::readFile($cgroupDir . '/cpu/cpu.cfs_period_us'));

        return $quota > 0 && $period > 0 ? $quota / $period : null;
    }

    /** Número de CPUs do host que o container enxerga — a carga (load average) é do host inteiro. */
    public static function cpuCount(): ?int
    {
        $cpuinfo = self::readFile('/proc/cpuinfo');
        $count = $cpuinfo === null ? 0 : (int) preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }

    /** @return array<string, string> diretiva => valor */
    public static function phpSettings(): array
    {
        $settings = [];
        foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'session.gc_maxlifetime', 'opcache.validate_timestamps'] as $key) {
            $settings[$key] = (string) ini_get($key);
        }
        // Do pool do PHP-FPM (docker/php-fpm-pool.conf) — não é diretiva de php.ini, vem do ambiente.
        $settings['fpm.max_children'] = (string) (getenv('FPM_MAX_CHILDREN') ?: '');

        return $settings;
    }

    /** @return array<string, bool> extensão => carregada */
    public static function phpExtensions(): array
    {
        $loaded = [];
        foreach (['pdo_mysql', 'curl', 'zlib', 'mbstring', 'Zend OPcache'] as $ext) {
            $loaded[$ext] = extension_loaded($ext);
        }

        return $loaded;
    }

    /** @return array{enabled: bool, used: int, free: int, hitRate: ?float, scripts: int} */
    public static function opcache(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (!is_array($status) || !($status['opcache_enabled'] ?? false)) {
            return ['enabled' => false, 'used' => 0, 'free' => 0, 'hitRate' => null, 'scripts' => 0];
        }

        return [
            'enabled' => true,
            'used' => (int) ($status['memory_usage']['used_memory'] ?? 0),
            'free' => (int) ($status['memory_usage']['free_memory'] ?? 0),
            'hitRate' => isset($status['opcache_statistics']['opcache_hit_rate'])
                ? (float) $status['opcache_statistics']['opcache_hit_rate']
                : null,
            'scripts' => (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0),
        ];
    }

    // ---- Internos -------------------------------------------------------------------------

    private static function readFile(string $path): ?string
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return $contents === false ? null : $contents;
    }

    private static function directorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }

    /**
     * Apaga tudo DENTRO de $dir (mantém a pasta). Não segue symlink: um link plantado lá
     * dentro é removido como link, nunca o alvo.
     *
     * @return array{files: int, bytes: int}
     */
    private static function emptyDirectory(string $dir): array
    {
        $removed = ['files' => 0, 'bytes' => 0];
        if (!is_dir($dir)) {
            return $removed;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
                continue;
            }
            $size = $item->isLink() ? 0 : (int) $item->getSize();
            if (@unlink($path)) {
                $removed['files']++;
                $removed['bytes'] += $size;
            }
        }

        return $removed;
    }
}
