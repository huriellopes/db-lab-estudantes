<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;
use DateTimeImmutable;
use Throwable;

/**
 * Log de erros da aplicação em JSON-lines, um arquivo por dia em storage/logs/ — é o que
 * GET /admin/logs lê. Complementa (não substitui) o error_log pro stderr: `docker logs`
 * continua tendo tudo. Nunca grava dados da requisição além de método + caminho (sem query
 * string, que pode carregar token de reset de senha) e o id do usuário.
 */
final class ErrorLogger
{
    public const RETENTION_DAYS = 14;
    private const MAX_TRACE_CHARS = 4_000;

    private static ?string $dir = null;

    /** Só pros testes: aponta pra um diretório temporário. */
    public static function useDirectory(?string $dir): void
    {
        self::$dir = $dir;
    }

    public static function directory(): string
    {
        return self::$dir ?? dirname(__DIR__, 2) . '/storage/logs';
    }

    public static function exception(Throwable $e, string $level = 'error'): void
    {
        self::write($level, $e::class . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine(), $e->getTraceAsString());
    }

    public static function message(string $level, string $message, string $location = ''): void
    {
        self::write($level, $message, $location, '');
    }

    /** @return array<string, mixed> */
    public static function buildEntry(string $level, string $message, string $location, string $trace, array $server, ?int $userId): array
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return [
            'time' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'message' => mb_substr($message, 0, 1_000),
            'location' => $location,
            'route' => trim(($server['REQUEST_METHOD'] ?? 'CLI') . ' ' . (is_string($path) ? $path : '')),
            'user_id' => $userId,
            'trace' => mb_substr($trace, 0, self::MAX_TRACE_CHARS),
        ];
    }

    private static function write(string $level, string $message, string $location, string $trace): void
    {
        try {
            $dir = self::directory();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                return;
            }

            $userId = PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE ? null : Auth::id();
            $entry = self::buildEntry($level, $message, $location, $trace, $_SERVER, $userId);
            $file = $dir . '/app-' . date('Y-m-d') . '.log';
            $isNew = !is_file($file);

            @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

            if ($isNew) {
                self::prune();
            }
        } catch (Throwable) {
            // Logar o erro nunca pode virar outro erro.
        }
    }

    /** Apaga arquivos mais velhos que RETENTION_DAYS — roda uma vez por dia (ao criar o arquivo do dia). */
    public static function prune(): void
    {
        $limit = (new DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d');
        foreach (self::files() as $date => $file) {
            if ($date < $limit) {
                @unlink($file);
            }
        }
    }

    /** @return array<string, string> data (Y-m-d) => caminho, mais recente primeiro. */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::directory() . '/app-*.log') ?: [] as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) === 1) {
                $files[$m[1]] = $file;
            }
        }
        krsort($files);

        return $files;
    }

    /**
     * Entradas de um dia, mais recentes primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public static function entriesFor(string $date, ?string $level = null): array
    {
        $file = self::files()[$date] ?? null;
        if ($file === null) {
            return [];
        }

        $entries = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($level === null || ($row['level'] ?? null) === $level)) {
                $entries[] = $row;
            }
        }

        return array_reverse($entries);
    }

    /**
     * Agrupa por mensagem — "problemas recorrentes": o mesmo erro 200 vezes vira uma linha só.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{message: string, level: string, location: string, count: int, last: string}>
     */
    public static function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $key = ($e['level'] ?? '') . '|' . ($e['message'] ?? '') . '|' . ($e['location'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'message' => (string) ($e['message'] ?? ''),
                    'level' => (string) ($e['level'] ?? ''),
                    'location' => (string) ($e['location'] ?? ''),
                    'count' => 0,
                    'last' => (string) ($e['time'] ?? ''),
                ];
            }
            $groups[$key]['count']++;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }

    public static function countSince(DateTimeImmutable $since): int
    {
        $count = 0;
        foreach (self::files() as $date => $_) {
            if ($date < $since->format('Y-m-d')) {
                break;
            }
            foreach (self::entriesFor($date) as $e) {
                if (($e['time'] ?? '') >= $since->format(DATE_ATOM)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
