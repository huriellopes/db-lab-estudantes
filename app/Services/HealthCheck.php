<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Support\HealthStatus;
use Throwable;

/**
 * Saúde do lab: aplicação, MySQL e phpMyAdmin. O resultado fica em cache por alguns
 * segundos num arquivo em storage/ — o card aparece em todo /dashboard, e sem o cache cada
 * page view de cada aluno faria uma requisição HTTP ao phpMyAdmin.
 */
final class HealthCheck
{
    private const CACHE_TTL_SECONDS = 30;

    /** @return list<HealthStatus> */
    public static function all(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/cache/health.json';

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
        $diskOk = $free === false || $total === false || $total <= 0 || $free / $total > 0.05;
        $disk = $free !== false && $total !== false && $total > 0
            ? sprintf(', disco %d%% livre', (int) round($free / $total * 100))
            : '';

        return new HealthStatus('Aplicação', $diskOk && is_writable($storage), null, 'PHP ' . PHP_VERSION . $disk);
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

            return new HealthStatus('Banco de dados (MySQL)', false, null, 'Sem resposta');
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

        return new HealthStatus('phpMyAdmin', $ok, $ok ? $latency : null, $ok ? "HTTP {$code}" : 'Sem resposta');
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
