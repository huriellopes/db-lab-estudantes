<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\ErDiagram as ErDiagramEntity;

/**
 * Diagramas do laboratório de modelagem (MER/DER) salvos por conta — ver
 * App\Controllers\ErDiagramController. Toda leitura/escrita é sempre filtrada por
 * user_id, igual App\Models\SavedQuery.
 */
final class ErDiagram
{
    /** @return list<ErDiagramEntity> */
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, data, created_at, updated_at
             FROM er_diagrams WHERE user_id = ? ORDER BY updated_at DESC',
        );
        $stmt->execute([$userId]);

        return array_map(ErDiagramEntity::fromRow(...), $stmt->fetchAll());
    }

    public static function create(int $userId, string $title, string $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO er_diagrams (user_id, title, data) VALUES (?, ?, ?)',
        );
        $stmt->execute([$userId, $title, $data]);

        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $title, string $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE er_diagrams SET title = ?, data = ? WHERE id = ?',
        );
        $stmt->execute([$title, $data, $id]);
    }

    public static function findOwned(int $id, int $userId): ?ErDiagramEntity
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, data, created_at, updated_at
             FROM er_diagrams WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : ErDiagramEntity::fromRow($row);
    }
}
