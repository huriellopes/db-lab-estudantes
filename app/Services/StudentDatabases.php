<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\InstitutionMember;
use App\Models\SchemaRecord;
use App\Support\AuthenticatedUser;
use App\Support\InstitutionScope;
use App\Support\Role;
use App\Support\RowEditSql;
use PDO;

/**
 * Leitura dos bancos dos alunos para a tela do professor. Estrutura e dados vêm pela conexão do
 * PRÓPRIO professor (permissões de ProfessorGrants): o MySQL só mostra o que ele pode ver.
 * A conexão é unbuffered (Database::connectAs) — toda consulta aqui termina em fetchAll().
 */
final class StudentDatabases
{
    private const BINARY_TYPES = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];

    /** @return list<array{institution: string, students: list<array{id: int, name: string, email: string, schemas: list<string>}>}> */
    public static function visibleTo(AuthenticatedUser $prof): array
    {
        if ($prof->role !== Role::Professor) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            "SELECT i.name AS institution, u.id, u.name, u.email, sc.db_name
             FROM institution_members p
             INNER JOIN institutions i ON i.id = p.institution_id
             INNER JOIN institution_members s ON s.institution_id = p.institution_id AND s.role = 'aluno'
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN schemas_criados sc ON sc.user_id = u.id
             WHERE p.user_id = ? AND p.role = 'professor'
             ORDER BY i.name, u.name, sc.db_name",
        );
        $stmt->execute([$prof->id]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $r) {
            $student = &$grouped[$r['institution']][(int) $r['id']];
            $student ??= ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'schemas' => []];
            if ($r['db_name'] !== null) {
                $student['schemas'][] = (string) $r['db_name'];
            }
            unset($student);
        }

        $out = [];
        foreach ($grouped as $institution => $students) {
            $out[] = ['institution' => (string) $institution, 'students' => array_values($students)];
        }

        return $out;
    }

    public static function canAccess(AuthenticatedUser $prof, string $db): bool
    {
        $schema = SchemaRecord::findByName($db);

        return $schema !== null && $prof->role === Role::Professor && InstitutionScope::canSeeStudent(
            $prof->role,
            InstitutionMember::institutionIdsOf($prof->id),
            InstitutionMember::institutionOfStudent($schema->userId),
        );
    }

    public static function ownerName(string $db): ?string
    {
        $stmt = Database::connection()->prepare('SELECT u.name FROM schemas_criados s INNER JOIN users u ON u.id = s.user_id WHERE s.db_name = ?');
        $stmt->execute([$db]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /** Conexão como o professor logado; null quando a senha MySQL não está em cache na sessão. */
    public static function connectionFor(AuthenticatedUser $prof): ?PDO
    {
        $password = Auth::mysqlPassword();

        return $password === null ? null : Database::connectAs($prof->mysqlLogin, $password);
    }

    /** @return list<array{name: string, rows: int, primaryKey: list<string>, columns: list<array<string, mixed>>, foreignKeys: list<array{column: string, refTable: string, refColumn: string}>}> */
    public static function structure(PDO $pdo, string $db): array
    {
        $q = static function (string $sql) use ($pdo, $db): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$db]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $tables = [];
        foreach ($q("SELECT TABLE_NAME, COALESCE(TABLE_ROWS, 0) AS approx FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $tables[$t['TABLE_NAME']] = ['name' => (string) $t['TABLE_NAME'], 'rows' => (int) $t['approx'], 'primaryKey' => [], 'columns' => [], 'foreignKeys' => []];
        }
        foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION') as $c) {
            if (!isset($tables[$c['TABLE_NAME']])) {
                continue; // view
            }
            $tables[$c['TABLE_NAME']]['columns'][] = [
                'name' => (string) $c['COLUMN_NAME'],
                'type' => (string) $c['COLUMN_TYPE'],
                'nullable' => $c['IS_NULLABLE'] === 'YES',
                'key' => (string) $c['COLUMN_KEY'],
                'default' => $c['COLUMN_DEFAULT'] !== null ? (string) $c['COLUMN_DEFAULT'] : null,
                'extra' => (string) $c['EXTRA'],
                'binary' => in_array(strtolower((string) $c['DATA_TYPE']), self::BINARY_TYPES, true),
            ];
        }
        foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION') as $k) {
            if (!isset($tables[$k['TABLE_NAME']])) {
                continue;
            }
            if ($k['CONSTRAINT_NAME'] === 'PRIMARY') {
                $tables[$k['TABLE_NAME']]['primaryKey'][] = (string) $k['COLUMN_NAME'];
            } elseif ($k['REFERENCED_TABLE_NAME'] !== null) {
                $tables[$k['TABLE_NAME']]['foreignKeys'][] = ['column' => (string) $k['COLUMN_NAME'], 'refTable' => (string) $k['REFERENCED_TABLE_NAME'], 'refColumn' => (string) $k['REFERENCED_COLUMN_NAME']];
            }
        }

        return array_values($tables);
    }

    /** @return ?array<string, mixed> */
    public static function table(PDO $pdo, string $db, string $table): ?array
    {
        foreach (self::structure($pdo, $db) as $t) {
            if ($t['name'] === $table) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $table Item de structure().
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function rows(PDO $pdo, string $db, array $table, int $page, int $perPage = 25): array
    {
        $from = RowEditSql::ident($db) . '.' . RowEditSql::ident($table['name']);
        $total = (int) $pdo->query("SELECT COUNT(*) FROM {$from}")->fetchAll(PDO::FETCH_COLUMN)[0];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $order = $table['primaryKey'] !== [] ? ' ORDER BY ' . implode(', ', array_map(RowEditSql::ident(...), $table['primaryKey'])) : '';
        $rows = $pdo->query("SELECT * FROM {$from}{$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage))->fetchAll(PDO::FETCH_ASSOC);

        $binary = array_column(array_filter($table['columns'], static fn ($c) => $c['binary']), 'name');
        foreach ($rows as &$row) {
            foreach ($binary as $column) {
                if ($row[$column] !== null) {
                    $row[$column] = '[binário]';
                }
            }
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
