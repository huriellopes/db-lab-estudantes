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

    public static function create(int $institutionId, string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO classes (institution_id, name) VALUES (?, ?)')->execute([$institutionId, $name]);

        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE classes SET name = ? WHERE id = ?')->execute([$name, $id]);
    }
}
