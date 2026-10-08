<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\ProgrammableObjects;
use PDO;
use Throwable;

/**
 * Quarentena de um schema excluído: as tabelas vão inteiras (dados, índices, FKs) pra um
 * database oculto `_lixeira_s<id do schema>` com um único RENAME TABLE (atômico no MySQL),
 * fora do prefixo do aluno — então ele não enxerga nem mexe. Views, triggers, rotinas e
 * eventos não atravessam database: a definição volta como script (ver ProgrammableObjects).
 *
 * Nomes de database aqui vêm sempre do próprio banco (schemas_criados / deleted_models),
 * nunca de input; mesmo assim só passam se baterem com SAFE_NAME.
 */
final class SchemaQuarantine
{
    private const SAFE_NAME = '/^[A-Za-z0-9_]{1,64}$/';

    public static function nameFor(int $schemaId): string
    {
        return '_lixeira_s' . $schemaId;
    }

    /** @return array{quarantine: string, objects: list<string>} */
    public static function move(string $dbName, int $schemaId): array
    {
        $pdo = Database::connection();
        $quarantine = self::nameFor($schemaId);
        self::assertSafe($dbName, $quarantine);

        $objects = ProgrammableObjects::collect($pdo, $dbName);
        $tables = self::baseTables($pdo, $dbName);

        $pdo->exec("CREATE DATABASE `{$quarantine}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            // Trigger impede RENAME TABLE entre databases (erro 1435); a definição já está em $objects.
            foreach ($objects as $object) {
                if ($object['type'] === 'TRIGGER') {
                    $pdo->exec("DROP TRIGGER `{$dbName}`.`" . str_replace('`', '``', $object['name']) . '`');
                }
            }
            if ($tables !== []) {
                $pdo->exec('RENAME TABLE ' . implode(', ', array_map(
                    static fn (string $t): string => "`{$dbName}`.`{$t}` TO `{$quarantine}`.`{$t}`",
                    $tables,
                )));
            }
        } catch (Throwable $e) {
            // RENAME TABLE é atômico: se falhou, nada foi movido e a quarentena está vazia.
            $pdo->exec("DROP DATABASE IF EXISTS `{$quarantine}`");
            $dropped = array_values(array_map(
                static fn (array $o): string => $o['sql'],
                array_filter($objects, static fn (array $o): bool => $o['type'] === 'TRIGGER'),
            ));
            throw new ArchiveException("Não foi possível mover o schema {$dbName} para a quarentena.", $dropped, $e);
        }

        // Leva junto views/rotinas/eventos que sobraram (já salvos em $objects).
        $pdo->exec("DROP DATABASE `{$dbName}`");

        return ['quarantine' => $quarantine, 'objects' => array_column($objects, 'sql')];
    }

    public static function restore(string $dbName, string $quarantine, string $ownerLogin): void
    {
        $pdo = Database::connection();
        self::assertSafe($dbName, $quarantine);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tables = self::baseTables($pdo, $quarantine);
        if ($tables !== []) {
            $pdo->exec('RENAME TABLE ' . implode(', ', array_map(
                static fn (string $t): string => "`{$quarantine}`.`{$t}` TO `{$dbName}`.`{$t}`",
                $tables,
            )));
        }
        $pdo->exec("DROP DATABASE `{$quarantine}`");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO " . $pdo->quote($ownerLogin) . "@'%'");
    }

    public static function purge(string $quarantine): void
    {
        self::assertSafe($quarantine);
        Database::connection()->exec("DROP DATABASE IF EXISTS `{$quarantine}`");
    }

    public static function exists(string $dbName): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbName]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param list<string> $dbNames
     * @return array<string, int>
     */
    public static function sizeBytes(array $dbNames): array
    {
        $sizes = array_fill_keys($dbNames, 0);
        if ($dbNames === []) {
            return $sizes;
        }
        $stmt = Database::connection()->prepare(
            'SELECT TABLE_SCHEMA, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA IN (' . implode(',', array_fill(0, count($dbNames), '?')) . ') GROUP BY TABLE_SCHEMA',
        );
        $stmt->execute($dbNames);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $db => $bytes) {
            $sizes[$db] = (int) $bytes;
        }

        return $sizes;
    }

    /** @return list<string> */
    private static function baseTables(PDO $pdo, string $dbName): array
    {
        $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $stmt->execute([$dbName]);

        return array_map(static fn (string $t): string => str_replace('`', '``', $t), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function assertSafe(string ...$names): void
    {
        foreach ($names as $name) {
            if (preg_match(self::SAFE_NAME, $name) !== 1) {
                throw new ArchiveException("Nome de database inesperado: {$name}");
            }
        }
    }
}
