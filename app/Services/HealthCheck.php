<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Support\ByteSize;
use App\Support\HealthStatus;
use DateTimeImmutable;
use Throwable;

/**
 * Saúde do lab: aplicação, MySQL e phpMyAdmin. O resultado fica em cache por alguns
 * segundos num arquivo em storage/ — o card aparece em todo /dashboard, e sem o cache cada
 * page view de cada aluno faria uma requisição HTTP ao phpMyAdmin.
 */
final class HealthCheck
{
    public const CACHE_TTL_SECONDS = 30;

    /** Abaixo disso (fração livre da partição do storage/) a Aplicação aparece offline. */
    public const MIN_FREE_DISK_RATIO = 0.05;

    public static function cacheFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/health.json';
    }

    /** Quando o resultado em cache foi gerado (null = sem cache, a próxima leitura checa na hora). */
    public static function checkedAt(): ?DateTimeImmutable
    {
        $mtime = is_file(self::cacheFile()) ? filemtime(self::cacheFile()) : false;

        return $mtime === false ? null : (new DateTimeImmutable())->setTimestamp($mtime);
    }

    /** Joga o cache fora — o próximo all() checa os três serviços de novo ("Reverificar agora"). */
    public static function forget(): void
    {
        @unlink(self::cacheFile());
    }

    /** @return list<HealthStatus> */
    public static function all(): array
    {
        $cacheFile = self::cacheFile();

        if (is_file($cacheFile) && time() - (int) filemtime($cacheFile) < self::CACHE_TTL_SECONDS) {
            $rows = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($rows)) {
                return array_map(HealthStatus::fromArray(...), $rows);
            }
        }

        $statuses = [self::app(), self::mysql(), self::phpMyAdmin()];

        if (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0775, true)) {
            @file_put_contents(
                $cacheFile,
                json_encode(array_map(static fn (HealthStatus $s): array => $s->toArray(), $statuses)),
                LOCK_EX,
            );
        }

        return $statuses;
    }

    private static function app(): HealthStatus
    {
        $storage = dirname(__DIR__, 2) . '/storage';
        $free = @disk_free_space($storage);
        $total = @disk_total_space($storage);

        return self::describeApp(
            PHP_VERSION,
            $free === false ? null : $free,
            $total === false ? null : $total,
            is_writable($storage),
        );
    }

    /**
     * O "% livre" é do DISCO (partição onde fica storage/: logs, backups, caches), não do PHP —
     * por isso o texto diz "disco" explicitamente. Sem leitura de disco (null), não reprova.
     */
    public static function describeApp(string $phpVersion, ?float $freeBytes, ?float $totalBytes, bool $storageWritable): HealthStatus
    {
        $hasDisk = $freeBytes !== null && $totalBytes !== null && $totalBytes > 0;
        $diskOk = !$hasDisk || $freeBytes / $totalBytes > self::MIN_FREE_DISK_RATIO;

        $detail = 'PHP ' . $phpVersion;
        if ($hasDisk) {
            $detail .= sprintf(
                ' · disco: %s livres de %s (%d%%)',
                ByteSize::format($freeBytes),
                ByteSize::format($totalBytes),
                (int) round($freeBytes / $totalBytes * 100),
            );
        }

        $hint = '';
        if (!$storageWritable) {
            $detail .= ' · storage/ sem permissão de escrita';
            $hint = 'Corrija a permissão no servidor: docker compose exec app chown -R www-data:www-data storage (ou reinicie o container: o entrypoint corrige)';
        } elseif (!$diskOk) {
            $detail .= ' · disco com menos de ' . (int) (self::MIN_FREE_DISK_RATIO * 100) . '% livre';
            $hint = 'Use "Liberar espaço" em /admin/manutencao (logs e backups antigos) ou, no servidor, '
                . 'docker system prune para apagar imagens e containers parados.';
        }

        return new HealthStatus('Aplicação', $diskOk && $storageWritable, null, $detail, $hint);
    }

    private static function mysql(): HealthStatus
    {
        $start = hrtime(true);

        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            $status = $pdo->query(
                "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected')",
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            return new HealthStatus('Banco de dados (MySQL)', true, $latency, sprintf(
                'MySQL %s, %s conexões, no ar há %s',
                $version,
                $status['Threads_connected'] ?? '?',
                self::humanDuration((int) ($status['Uptime'] ?? 0)),
            ));
        } catch (Throwable $e) {
            error_log('HealthCheck MySQL: ' . $e->getMessage());

            return new HealthStatus(
                'Banco de dados (MySQL)',
                false,
                null,
                'Sem resposta',
                'No servidor: docker compose ps mysql para ver o estado, docker compose logs --tail=100 mysql '
                . 'para o motivo e docker compose restart mysql para reiniciar.',
            );
        }
    }

    private static function phpMyAdmin(): HealthStatus
    {
        $url = (string) Config::get('PMA_INTERNAL_URL', 'http://phpmyadmin');
        $start = hrtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $latency = (int) ((hrtime(true) - $start) / 1_000_000);

        $ok = $code >= 200 && $code < 400;

        return new HealthStatus(
            'phpMyAdmin',
            $ok,
            $ok ? $latency : null,
            $ok ? "HTTP {$code}" : ($code > 0 ? "HTTP {$code}" : 'Sem resposta'),
            $ok ? '' : 'No servidor: docker compose restart phpmyadmin (veja o motivo em docker compose logs --tail=100 phpmyadmin). '
                . 'O lab continua funcionando sem ele — só o acesso pelo phpMyAdmin fica fora.',
        );
    }

    public static function humanDuration(int $seconds): string
    {
        return match (true) {
            $seconds >= 86_400 => intdiv($seconds, 86_400) . 'd ' . intdiv($seconds % 86_400, 3_600) . 'h',
            $seconds >= 3_600 => intdiv($seconds, 3_600) . 'h ' . intdiv($seconds % 3_600, 60) . 'min',
            default => intdiv($seconds, 60) . 'min',
        };
    }
}
