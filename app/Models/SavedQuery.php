<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\SavedQuery as SavedQueryEntity;

/**
 * Comandos SQL que a pessoa escolheu guardar no console (ver App\Controllers\SavedQueryController)
 * pra reaproveitar depois sem precisar redigitar — biblioteca pessoal, nunca visível pra outra
 * conta (toda leitura/escrita aqui é sempre filtrada por user_id).
 */
final class SavedQuery
{
    /** @return list<SavedQueryEntity> */
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, schema_name, sql_text, created_at
             FROM saved_queries WHERE user_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$userId]);

        return array_map(SavedQueryEntity::fromRow(...), $stmt->fetchAll());
    }

    public static function create(int $userId, string $title, ?string $schemaName, string $sqlText): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO saved_queries (user_id, title, schema_name, sql_text) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$userId, $title, $schemaName !== '' ? $schemaName : null, $sqlText]);
    }

    public static function findOwned(int $id, int $userId): ?SavedQueryEntity
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, schema_name, sql_text, created_at
             FROM saved_queries WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : SavedQueryEntity::fromRow($row);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM saved_queries WHERE id = ?');
        $stmt->execute([$id]);
    }
}
