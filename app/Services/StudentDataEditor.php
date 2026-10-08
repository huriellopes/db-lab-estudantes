<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuthenticatedUser;
use App\Support\RowEditSql;
use PDO;
use PDOException;

/**
 * Inserir/alterar linhas no banco de um aluno, como o professor (a conta dele não tem DELETE —
 * ver ProfessorGrants). Tabela e colunas são conferidas contra a estrutura real antes de montar
 * o SQL; valores sempre como parâmetros. A auditoria registra onde, nunca os valores.
 */
final class StudentDataEditor
{
    /**
     * @param array<string, string> $key Valores atuais da chave primária.
     * @param array<string, string> $values Coluna => novo valor.
     * @param array<string, string> $nulls Colunas marcadas "NULL".
     */
    public static function update(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $key, array $values, array $nulls): void
    {
        $t = self::tableOrFail($pdo, $db, $table);
        if ($t['primaryKey'] === []) {
            throw new StudentDataException('Esta tabela não tem chave primária: não dá para alterar uma linha com segurança. Só leitura.');
        }
        $where = [];
        foreach ($t['primaryKey'] as $column) {
            if (!array_key_exists($column, $key)) {
                throw new StudentDataException('Linha não identificada (falta a chave primária).');
            }
            $where[$column] = $key[$column];
        }

        $changes = [];
        foreach (self::editable($t) as $column) {
            if (in_array($column['name'], $t['primaryKey'], true)) {
                continue; // chave não é editável por aqui
            }
            if (isset($nulls[$column['name']]) && $column['nullable']) {
                $changes[$column['name']] = null;
            } elseif (array_key_exists($column['name'], $values)) {
                $changes[$column['name']] = $values[$column['name']];
            }
        }
        if ($changes === []) {
            throw new StudentDataException('Nenhuma coluna editável foi enviada.');
        }

        self::run($pdo, RowEditSql::update($db, $table, $where, $changes));
        AuditLog::record('professor.db_row_updated', 'schema', $db, ['tabela' => $table, 'colunas' => implode(', ', array_keys($changes)), 'chave' => json_encode($where, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @param array<string, string> $values Coluna => valor (vazio = usa o padrão da coluna).
     * @param array<string, string> $nulls Colunas marcadas "NULL".
     */
    public static function insert(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $values, array $nulls): void
    {
        $t = self::tableOrFail($pdo, $db, $table);
        $row = [];
        foreach (self::editable($t) as $column) {
            if (isset($nulls[$column['name']]) && $column['nullable']) {
                $row[$column['name']] = null;
            } elseif (($values[$column['name']] ?? '') !== '') {
                $row[$column['name']] = $values[$column['name']];
            }
        }

        self::run($pdo, RowEditSql::insert($db, $table, $row));
        AuditLog::record('professor.db_row_inserted', 'schema', $db, ['tabela' => $table, 'colunas' => implode(', ', array_keys($row))]);
    }

    /** @return array<string, mixed> */
    private static function tableOrFail(PDO $pdo, string $db, string $table): array
    {
        return StudentDatabases::table($pdo, $db, $table) ?? throw new StudentDataException('Tabela não encontrada neste banco.');
    }

    /**
     * @param array<string, mixed> $table
     * @return list<array<string, mixed>>
     */
    private static function editable(array $table): array
    {
        return array_values(array_filter($table['columns'], static fn (array $c): bool => !$c['binary'] && !str_contains(strtolower($c['extra']), 'generated')));
    }

    /** @param array{0: string, 1: list<scalar|null>} $statement */
    private static function run(PDO $pdo, array $statement): void
    {
        try {
            $pdo->prepare($statement[0])->execute($statement[1]);
        } catch (PDOException $e) {
            // Erro do MySQL do aluno (tipo inválido, FK, duplicado...) é útil pro professor — sem stack.
            throw new StudentDataException('O MySQL recusou: ' . ($e->errorInfo[2] ?? $e->getMessage()), 0, $e);
        }
    }
}
