<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Support\LikePattern;
use PDO;

/** Tabela class_members: responsáveis (professor) e alunos de cada turma. */
final class ClassMember
{
    /** @return list<array{id: int, user_id: int, role: string, name: string, email: string, mysql_login: string}> */
    public static function forClass(int $classId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.user_id, m.role, u.name, u.email, u.mysql_login
             FROM class_members m INNER JOIN users u ON u.id = m.user_id WHERE m.class_id = ? ORDER BY u.name',
        );
        $stmt->execute([$classId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id']] + $r, $stmt->fetchAll());
    }

    /** @return ?array{id: int, class_id: int, user_id: int, role: string} */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, class_id, user_id, role FROM class_members WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : ['id' => (int) $r['id'], 'class_id' => (int) $r['class_id'], 'user_id' => (int) $r['user_id'], 'role' => (string) $r['role']];
    }

    public static function add(int $classId, int $userId, string $role): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO class_members (class_id, user_id, role) VALUES (?, ?, ?)')->execute([$classId, $userId, $role]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<int> */
    public static function professorIdsOf(int $classId): array
    {
        $stmt = Database::connection()->prepare("SELECT user_id FROM class_members WHERE class_id = ? AND role = 'professor' ORDER BY id");
        $stmt->execute([$classId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function isMember(int $classId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM class_members WHERE class_id = ? AND user_id = ?');
        $stmt->execute([$classId, $userId]);

        return $stmt->fetch() !== false;
    }

    /** @return list<array{id: int, class_id: int}> Vínculos de turma da pessoa (opcionalmente só numa instituição). */
    public static function forUser(int $userId, ?int $institutionId = null): array
    {
        $sql = 'SELECT m.id, m.class_id FROM class_members m INNER JOIN classes c ON c.id = m.class_id WHERE m.user_id = ?';
        $args = [$userId];
        if ($institutionId !== null) {
            $sql .= ' AND c.institution_id = ?';
            $args[] = $institutionId;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY m.id');
        $stmt->execute($args);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'class_id' => (int) $r['class_id']], $stmt->fetchAll());
    }

    /**
     * Sugestões do autocomplete "adicionar à turma": pessoas da instituição da turma que ainda
     * não estão nela (o papel na turma vem da conta: professor entra como responsável).
     *
     * @return list<array{id: int, name: string, email: string, mysql_login: string, role: string}>
     */
    public static function candidates(int $classId, string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = LikePattern::contains($query);
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.name, u.email, u.mysql_login, u.role FROM users u
             INNER JOIN institution_members im ON im.user_id = u.id
             INNER JOIN classes c ON c.institution_id = im.institution_id AND c.id = ?
             WHERE (u.name LIKE ? OR u.email LIKE ? OR u.mysql_login LIKE ?)
               AND NOT EXISTS (SELECT 1 FROM class_members m WHERE m.class_id = c.id AND m.user_id = u.id)
             ORDER BY u.name LIMIT ' . max(1, min($limit, 20)),
        );
        $stmt->execute([$classId, $like, $like, $like]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id: int, name: string, institution: string, professors: string}> "Minhas turmas" do aluno. */
    public static function classesOfStudent(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.id, c.name, i.name AS institution,
                    COALESCE((SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ')
                              FROM class_members p INNER JOIN users u ON u.id = p.user_id
                              WHERE p.class_id = c.id AND p.role = 'professor'), '') AS professors
             FROM class_members m
             INNER JOIN classes c ON c.id = m.class_id
             INNER JOIN institutions i ON i.id = c.institution_id
             WHERE m.user_id = ? AND m.role = 'aluno'
             ORDER BY c.name",
        );
        $stmt->execute([$userId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'institution' => (string) $r['institution'], 'professors' => (string) $r['professors']], $stmt->fetchAll());
    }
}
