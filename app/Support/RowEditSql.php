<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * SQL de inserir/alterar uma linha no banco de um aluno (tela do professor). Identificadores
 * entre crases (crase escapada); valores sempre como parâmetros. Não existe DELETE aqui — e a
 * conta do professor nem tem esse privilégio (ver App\Services\ProfessorGrants).
 */
final class RowEditSql
{
    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param array<string, scalar> $key Coluna(s) da chave primária => valor atual.
     * @param array<string, scalar|null> $changes Coluna => novo valor (null = NULL).
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function update(string $db, string $table, array $key, array $changes): array
    {
        if ($key === [] || $changes === []) {
            throw new InvalidArgumentException('UPDATE precisa de chave e de pelo menos uma coluna alterada.');
        }

        $set = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $set[] = self::ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $where = [];
        foreach ($key as $column => $value) {
            $where[] = self::ident((string) $column) . ' = ?';
            $params[] = $value;
        }

        return [
            'UPDATE ' . self::ident($db) . '.' . self::ident($table) . ' SET ' . implode(', ', $set)
                . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params,
        ];
    }

    /**
     * @param array<string, scalar|null> $values Coluna => valor; colunas omitidas usam o padrão.
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function insert(string $db, string $table, array $values): array
    {
        $columns = array_map(static fn ($c): string => self::ident((string) $c), array_keys($values));

        return [
            'INSERT INTO ' . self::ident($db) . '.' . self::ident($table) . ' (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')',
            array_values($values),
        ];
    }
}
