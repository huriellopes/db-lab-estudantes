<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\ErrorLogger;
use PDO;
use Throwable;

/**
 * `tail -f` das fontes de log que a aplicação consegue ler sozinha, pra GET /admin/logs/ao-vivo.
 * A página pergunta a cada ~2s "o que tem de novo depois do cursor X?" — polling curto em vez
 * de SSE/stream de propósito: uma conexão aberta prenderia um worker do PHP-FPM por aba.
 *
 * Fontes:
 *  - app: log de erros do dia (storage/logs/app-AAAA-MM-DD.log, ver ErrorLogger) — cursor é
 *    "data:byte"; virou o dia ou o arquivo encolheu (prune), recomeça do início do arquivo novo.
 *  - mysql: performance_schema.error_log do MySQL 8 (o appuser tem SELECT nele) — cursor é o
 *    LOGGED da última linha.
 *  - audit: audit_logs — cursor é o id.
 *
 * nginx e PHP-FPM escrevem só no stdout do container (docker/supervisord.conf), então não dão
 * pra ler daqui: pra eles, `docker compose logs -f app`.
 *
 * Cada entrada sai no mesmo formato: {time, level, message, detail}.
 */
final class LogTail
{
    public const SOURCES = ['app', 'mysql', 'audit'];

    /** Linhas na primeira carga (cursor vazio) e teto por resposta. */
    public const BACKLOG = 100;
    private const MAX_BYTES_PER_READ = 512 * 1024;

    /**
     * @return array{entries: list<array{time: string, level: string, message: string, detail: string}>, cursor: string, error?: string}
     */
    public static function read(string $source, string $cursor): array
    {
        try {
            return match ($source) {
                'app' => self::app($cursor),
                'mysql' => self::mysql($cursor),
                'audit' => self::audit($cursor),
            };
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'warning');

            return ['entries' => [], 'cursor' => $cursor, 'error' => 'Não foi possível ler esta fonte agora.'];
        }
    }

    // ---- app ------------------------------------------------------------------------------

    /** @return array{entries: list<array<string, string>>, cursor: string} */
    public static function app(string $cursor, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $file = ErrorLogger::directory() . "/app-{$today}.log";
        $size = is_file($file) ? (int) filesize($file) : 0;

        [$cursorDay, $offset] = array_pad(explode(':', $cursor, 2), 2, '');
        $isFirstRead = $cursor === '';
        $offset = (int) $offset;
        // Outro dia (arquivo novo) ou arquivo menor que o cursor (apagado/recriado): do começo.
        if ($cursorDay !== $today || $offset > $size) {
            $offset = 0;
        }

        if ($size === 0 || $offset === $size) {
            return ['entries' => [], 'cursor' => "{$today}:{$size}"];
        }

        if ($isFirstRead) {
            // Primeira carga: só as últimas BACKLOG linhas, sem ler o arquivo inteiro.
            $lines = self::lastLines($file, self::BACKLOG);
            $end = $size;
        } else {
            $chunk = (string) file_get_contents($file, false, null, $offset, self::MAX_BYTES_PER_READ);
            // Só até a última quebra de linha: uma linha ainda sendo escrita fica pra próxima rodada.
            $complete = strrpos($chunk, "\n");
            if ($complete === false) {
                return ['entries' => [], 'cursor' => "{$today}:{$offset}"];
            }
            $lines = explode("\n", substr($chunk, 0, $complete));
            $end = $offset + $complete + 1;
        }

        $entries = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $detail = trim(implode("\n", array_filter([
                (string) ($row['route'] ?? ''),
                (string) ($row['location'] ?? ''),
                isset($row['user_id']) ? 'usuário #' . $row['user_id'] : '',
                (string) ($row['trace'] ?? ''),
            ])));
            $entries[] = [
                'time' => (string) ($row['time'] ?? ''),
                'level' => (string) ($row['level'] ?? 'error'),
                'message' => (string) ($row['message'] ?? ''),
                'detail' => $detail,
            ];
        }

        return ['entries' => $entries, 'cursor' => "{$today}:{$end}"];
    }

    /** @return list<string> */
    private static function lastLines(string $file, int $count): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $size = (int) fstat($handle)['size'];
        $buffer = '';
        $pos = $size;
        while ($pos > 0 && substr_count($buffer, "\n") <= $count) {
            $read = min(8192, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read) . $buffer;
        }
        fclose($handle);

        $lines = array_values(array_filter(explode("\n", $buffer), static fn (string $l): bool => $l !== ''));
        // Se não chegou no começo do arquivo, a primeira linha do buffer pode estar cortada.
        if ($pos > 0) {
            array_shift($lines);
        }

        return array_slice($lines, -$count);
    }

    // ---- mysql ----------------------------------------------------------------------------

    /** @return array{entries: list<array<string, string>>, cursor: string} */
    private static function mysql(string $cursor): array
    {
        $pdo = Database::connection();
        $columns = 'LOGGED, PRIO, ERROR_CODE, SUBSYSTEM, DATA';

        if ($cursor === '') {
            $rows = array_reverse($pdo->query(
                "SELECT {$columns} FROM performance_schema.error_log ORDER BY LOGGED DESC LIMIT " . self::BACKLOG,
            )->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $stmt = $pdo->prepare(
                "SELECT {$columns} FROM performance_schema.error_log WHERE LOGGED > ? ORDER BY LOGGED LIMIT " . self::BACKLOG,
            );
            $stmt->execute([$cursor]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $entries = array_map(static fn (array $r): array => [
            'time' => (string) $r['LOGGED'],
            'level' => self::mysqlLevel((string) $r['PRIO']),
            'message' => (string) $r['DATA'],
            'detail' => trim($r['SUBSYSTEM'] . ' · ' . $r['ERROR_CODE'], ' ·'),
        ], $rows);

        $last = $rows === [] ? null : end($rows);

        return ['entries' => $entries, 'cursor' => $last !== null ? (string) $last['LOGGED'] : ($cursor !== '' ? $cursor : self::mysqlNow($pdo))];
    }

    /** Sem nenhuma linha ainda, começa "de agora" — senão a próxima rodada pegaria tudo de novo. */
    private static function mysqlNow(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT NOW(6)')->fetchColumn();
    }

    public static function mysqlLevel(string $prio): string
    {
        return match (strtolower($prio)) {
            'error' => 'error',
            'warning' => 'warning',
            'system' => 'notice',
            default => 'info',
        };
    }

    // ---- audit ----------------------------------------------------------------------------

    /** @return array{entries: list<array<string, string>>, cursor: string} */
    private static function audit(string $cursor): array
    {
        $pdo = Database::connection();
        $columns = 'id, created_at, user_name, action, target_type, target_id, meta, ip';

        if ($cursor === '') {
            $rows = array_reverse($pdo->query(
                "SELECT {$columns} FROM audit_logs ORDER BY id DESC LIMIT " . self::BACKLOG,
            )->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $stmt = $pdo->prepare("SELECT {$columns} FROM audit_logs WHERE id > ? ORDER BY id LIMIT " . self::BACKLOG);
            $stmt->execute([(int) $cursor]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $entries = array_map(static function (array $r): array {
            $target = trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? ''));

            return [
                'time' => (string) $r['created_at'],
                'level' => str_contains((string) $r['action'], 'failed') ? 'warning' : 'info',
                'message' => trim(($r['user_name'] ?? 'sistema') . ' → ' . $r['action'] . ($target !== '' ? " ({$target})" : '')),
                'detail' => trim(($r['meta'] ?? '') . ($r['ip'] ? "\nIP " . $r['ip'] : '')),
            ];
        }, $rows);

        $last = $rows === [] ? null : end($rows);

        return ['entries' => $entries, 'cursor' => $last !== null ? (string) $last['id'] : $cursor];
    }
}
