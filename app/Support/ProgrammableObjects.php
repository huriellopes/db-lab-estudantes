<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Views, triggers, rotinas e eventos de um schema que vai pra quarentena (ver
 * App\Services\SchemaQuarantine). RENAME TABLE entre databases não leva nada disso (e
 * falha com trigger, erro 1435), e recriar com o aluno como DEFINER exigiria SET_USER_ID pro
 * appuser — privilégio que ele não tem de propósito. Então as definições viram um script que
 * o próprio aluno roda no console, sem DEFINER: o objeto nasce com ele como dono.
 */
final class ProgrammableObjects
{
    /** Ordem de recriação: view antes de rotina que a usa, trigger depois das tabelas. */
    private const ORDER = ['VIEW', 'FUNCTION', 'PROCEDURE', 'TRIGGER', 'EVENT'];

    /** @return list<array{type: string, name: string, sql: string}> */
    public static function collect(PDO $pdo, string $dbName): array
    {
        $found = [];

        $names = static function (string $sql) use ($pdo, $dbName): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dbName]);

            return $stmt->fetchAll(PDO::FETCH_NUM);
        };

        foreach ($names('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME') as [$name]) {
            $found[] = ['VIEW', $name, 'SHOW CREATE VIEW', 'Create View'];
        }
        foreach ($names('SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME') as [$type, $name]) {
            $found[] = [$type, $name, "SHOW CREATE {$type}", $type === 'FUNCTION' ? 'Create Function' : 'Create Procedure'];
        }
        foreach ($names('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME') as [$name]) {
            $found[] = ['TRIGGER', $name, 'SHOW CREATE TRIGGER', 'SQL Original Statement'];
        }
        foreach ($names('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME') as [$name]) {
            $found[] = ['EVENT', $name, 'SHOW CREATE EVENT', 'Create Event'];
        }

        $objects = [];
        foreach ($found as [$type, $name, $show, $column]) {
            $row = $pdo->query("{$show} `{$dbName}`.`" . str_replace('`', '``', $name) . '`')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && ($row[$column] ?? null) !== null) {
                $objects[] = ['type' => $type, 'name' => $name, 'sql' => (string) $row[$column]];
            }
        }

        usort($objects, static fn (array $a, array $b): int => array_search($a['type'], self::ORDER, true) <=> array_search($b['type'], self::ORDER, true));

        return $objects;
    }

    public static function stripDefiner(string $sql): string
    {
        return (string) preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
    }

    /** @param list<string> $sqls */
    public static function restoreScript(array $sqls): string
    {
        $body = implode("\$\$\n\n", array_map(self::stripDefiner(...), $sqls));

        return "-- Views, triggers, rotinas e eventos deste schema, salvos quando ele foi excluído.\n"
            . "-- Rode este script inteiro no console (com este schema selecionado) para recriá-los.\n"
            . "DELIMITER \$\$\n{$body}\$\$\nDELIMITER ;\n";
    }
}
