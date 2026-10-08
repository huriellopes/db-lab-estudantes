<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Support\LikePattern;
use PDO;

/** Tabela institution_members (professor em várias, aluno em uma — ver a migration). */
final class InstitutionMember
{
    /** @return list<array{id: int, user_id: int, role: string, name: string, email: string, mysql_login: string}> */
    public static function forInstitution(int $institutionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.user_id, m.role, u.name, u.email, u.mysql_login
             FROM institution_members m INNER JOIN users u ON u.id = m.user_id
             WHERE m.institution_id = ? ORDER BY u.name',
        );
        $stmt->execute([$institutionId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id']] + $r, $stmt->fetchAll());
    }

    /** @return list<array{id: int, institution_id: int, role: string}> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, institution_id, role FROM institution_members WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'institution_id' => (int) $r['institution_id'], 'role' => (string) $r['role']], $stmt->fetchAll());
    }

    /** @return list<int> */
    public static function institutionIdsOf(int $userId): array
    {
        return array_column(self::forUser($userId), 'institution_id');
    }

    public static function institutionOfStudent(int $userId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT institution_id FROM institution_members WHERE student_user_id = ?');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<int, array{id: int, name: string}> user_id do aluno => instituição */
    public static function studentInstitutions(): array
    {
        $rows = Database::connection()->query(
            "SELECT m.user_id, i.id, i.name FROM institution_members m INNER JOIN institutions i ON i.id = m.institution_id WHERE m.role = 'aluno'",
        )->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['user_id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        return $map;
    }

    public static function studentsWithoutInstitution(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM users u WHERE u.role = 'aluno' AND NOT EXISTS (SELECT 1 FROM institution_members m WHERE m.user_id = u.id)",
        )->fetchColumn();
    }

    /** @return ?array{id: int, institution_id: int, user_id: int, role: string} */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, institution_id, user_id, role FROM institution_members WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : ['id' => (int) $r['id'], 'institution_id' => (int) $r['institution_id'], 'user_id' => (int) $r['user_id'], 'role' => (string) $r['role']];
    }

    public static function add(int $institutionId, int $userId, string $role): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO institution_members (institution_id, user_id, role) VALUES (?, ?, ?)')->execute([$institutionId, $userId, $role]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Sugestões do autocomplete "vincular pessoa" (admin): só quem ainda pode entrar nesta
     * instituição — não-admin, que ainda não está nela, e aluno que não está em outra.
     *
     * @return list<array{id: int, name: string, email: string, mysql_login: string, role: string}>
     */
    public static function candidates(int $institutionId, string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = LikePattern::contains($query);
        $stmt = Database::connection()->prepare(
            "SELECT u.id, u.name, u.email, u.mysql_login, u.role FROM users u
             WHERE u.role IN ('professor', 'aluno')
               AND (u.name LIKE ? OR u.email LIKE ? OR u.mysql_login LIKE ?)
               AND NOT EXISTS (SELECT 1 FROM institution_members m WHERE m.institution_id = ? AND m.user_id = u.id)
               AND (u.role = 'professor' OR NOT EXISTS (SELECT 1 FROM institution_members s WHERE s.student_user_id = u.id))
             ORDER BY u.name LIMIT " . max(1, min($limit, 20)),
        );
        $stmt->execute([$like, $like, $like, $institutionId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function setRoleForUser(int $userId, string $role): void
    {
        Database::connection()->prepare('UPDATE institution_members SET role = ? WHERE user_id = ?')->execute([$role, $userId]);
    }
}
