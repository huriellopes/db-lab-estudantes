<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\SchoolClass as SchoolClassEntity;

/** Tabela classes. Escrita passa por App\Services\ClassManager. */
final class SchoolClass
{
    private const SELECT = "SELECT c.*, i.name AS institution_name,
            (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id AND m.role = 'aluno') AS students,
            (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id AND m.role = 'professor') AS professors
        FROM classes c INNER JOIN institutions i ON i.id = c.institution_id";

    public static function find(int $id): ?SchoolClassEntity
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : SchoolClassEntity::fromRow($row);
    }

    /**
     * @param list<int> $institutionIds
     * @return list<SchoolClassEntity>
     */
    public static function forInstitutions(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE c.institution_id IN (' . implode(',', array_fill(0, count($institutionIds), '?')) . ') ORDER BY i.name, c.name');
        $stmt->execute(array_values($institutionIds));

        return array_map(SchoolClassEntity::fromRow(...), $stmt->fetchAll());
    }

    /** @return list<SchoolClassEntity> */
    public static function all(): array
    {
        return array_map(SchoolClassEntity::fromRow(...), Database::connection()->query(self::SELECT . ' ORDER BY i.name, c.name')->fetchAll());
    }

    public static function nameTaken(int $institutionId, string $name, int $exceptId = 0): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM classes WHERE institution_id = ? AND name = ? AND id <> ?');
        $stmt->execute([$institutionId, $name, $exceptId]);

        return $stmt->fetch() !== false;
    }

    public static function findByInviteCode(string $code): ?SchoolClassEntity
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE c.invite_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : SchoolClassEntity::fromRow($row);
    }

    /** Código em uso por outra turma — ou reservado por uma que está em Dados excluídos. */
    public static function inviteCodeTaken(string $code): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM classes WHERE invite_code = ?');
        $stmt->execute([$code]);

        return $stmt->fetch() !== false || DeletedModel::isReserved('class_invite_code', $code);
    }

    public static function setInviteCode(int $id, ?string $code): void
    {
        Database::connection()->prepare('UPDATE classes SET invite_code = ? WHERE id = ?')->execute([$code, $id]);
    }

    public static function create(int $institutionId, string $name, ?string $inviteCode = null): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO classes (institution_id, name, invite_code) VALUES (?, ?, ?)')->execute([$institutionId, $name, $inviteCode]);

        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE classes SET name = ? WHERE id = ?')->execute([$name, $id]);
    }
}
