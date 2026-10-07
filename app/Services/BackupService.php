<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Dump lógico (.sql.gz) de um database, feito em PHP puro via PDO — sem `mysqldump` na
 * imagem. Gera estrutura (SHOW CREATE TABLE/VIEW) + dados em INSERTs de até 500 linhas.
 * Não cobre triggers/rotinas/eventos: pro lab (tabelas de estudo) é suficiente; um dump
 * completo de produção continua sendo `mysqldump` no host (ver README, "Backups").
 *
 * Restauração fica fora da aplicação de propósito: importar no phpMyAdmin ou via
 * `gunzip < arquivo.sql.gz | mysql ...`.
 */
final class BackupService
{
    public const KEEP_LAST = 10;
    private const ROWS_PER_INSERT = 500;
    /** Nome gerado aqui: <db>_<AAAAmmdd-HHiiss>.sql.gz — qualquer outro nome é recusado (path traversal). */
    public const FILENAME_PATTERN = '/^[A-Za-z0-9_]{1,64}_\d{8}-\d{6}\.sql\.gz$/';

    private static ?string $dir = null;

    public static function useDirectory(?string $dir): void
    {
        self::$dir = $dir;
    }

    public static function directory(): string
    {
        return self::$dir ?? dirname(__DIR__, 2) . '/storage/backups';
    }

    public static function isValidFilename(string $name): bool
    {
        return preg_match(self::FILENAME_PATTERN, $name) === 1;
    }

    /** Caminho absoluto de um backup existente, ou null (nome inválido ou arquivo inexistente). */
    public static function path(string $name): ?string
    {
        if (!self::isValidFilename($name)) {
            return null;
        }
        $path = self::directory() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** @return list<array{name: string, size: int, createdAt: DateTimeImmutable}> Mais recente primeiro. */
    public static function all(): array
    {
        $list = [];
        foreach (glob(self::directory() . '/*.sql.gz') ?: [] as $file) {
            if (self::isValidFilename(basename($file))) {
                $list[] = [
                    'name' => basename($file),
                    'size' => (int) filesize($file),
                    'createdAt' => (new DateTimeImmutable())->setTimestamp((int) filemtime($file)),
                ];
            }
        }
        usort($list, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return $list;
    }

    /** Databases que dá pra copiar: o da aplicação + todos os schemas de alunos. @return list<string> */
    public static function availableDatabases(): array
    {
        $appDb = (string) Config::get('DB_NAME', 'schoolapp');
        $schemas = Database::connection()->query('SELECT db_name FROM schemas_criados ORDER BY db_name')->fetchAll(PDO::FETCH_COLUMN);

        return [$appDb, ...$schemas];
    }

    /** @return string Nome do arquivo gerado. */
    public static function create(string $database): string
    {
        if (!in_array($database, self::availableDatabases(), true) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new InvalidArgumentException('Database inválido para backup.');
        }

        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new RuntimeException("Não foi possível criar {$dir}.");
        }

        $name = $database . '_' . date('Ymd-His') . '.sql.gz';
        $tmp = $dir . '/.' . $name . '.tmp';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Não foi possível escrever {$tmp}.");
        }

        try {
            self::dump(Database::connection(), $database, static fn (string $chunk) => gzwrite($gz, $chunk));
        } finally {
            gzclose($gz);
        }

        rename($tmp, $dir . '/' . $name);
        self::prune();

        return $name;
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);

        return $path !== null && unlink($path);
    }

    /** @param callable(string): mixed $write */
    public static function dump(PDO $pdo, string $database, callable $write): void
    {
        $q = static fn (string $id): string => '`' . str_replace('`', '``', $id) . '`';

        $write("-- DB Lab Estudantes — backup de {$database}\n-- Gerado em " . date(DATE_ATOM) . "\n\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $write("CREATE DATABASE IF NOT EXISTS {$q($database)};\nUSE {$q($database)};\n\n");

        $tables = $pdo->query("SHOW FULL TABLES FROM {$q($database)}")->fetchAll(PDO::FETCH_NUM);
        $views = [];

        foreach ($tables as [$table, $type]) {
            if ($type === 'VIEW') {
                $views[] = $table;
                continue;
            }

            $create = $pdo->query("SHOW CREATE TABLE {$q($database)}.{$q($table)}")->fetch(PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS {$q($table)};\n{$create};\n\n");

            $stmt = $pdo->query("SELECT * FROM {$q($database)}.{$q($table)}", PDO::FETCH_NUM);
            $batch = [];
            $columns = null;
            foreach ($stmt as $row) {
                if ($columns === null) {
                    $columns = [];
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $columns[] = $q((string) $stmt->getColumnMeta($i)['name']);
                    }
                }
                $batch[] = '(' . implode(',', array_map(
                    static fn (mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $row,
                )) . ')';
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $write("INSERT INTO {$q($table)} (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $write("INSERT INTO {$q($table)} (" . implode(',', $columns) . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
        }

        foreach ($views as $view) {
            $create = $pdo->query("SHOW CREATE VIEW {$q($database)}.{$q($view)}")->fetch(PDO::FETCH_NUM)[1];
            $write("DROP VIEW IF EXISTS {$q($view)};\n{$create};\n\n");
        }

        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    private static function prune(): void
    {
        foreach (array_slice(self::all(), self::KEEP_LAST) as $old) {
            @unlink(self::directory() . '/' . $old['name']);
        }
    }
}
