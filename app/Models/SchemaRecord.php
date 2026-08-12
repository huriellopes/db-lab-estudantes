<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\Schema;
use App\Models\Entities\SchemaWithOwner;

/**
 * Registro (na tabela schemas_criados) de quem é dono de cada database criado.
 * A criação/remoção do database em si é responsabilidade de App\Services\SchemaProvisioner.
 */
final class SchemaRecord
{
    /** @return list<Schema> */
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, db_name, created_at FROM schemas_criados WHERE user_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$userId]);

        return array_map(Schema::fromRow(...), $stmt->fetchAll());
    }

    /** @return list<SchemaWithOwner> Todos os schemas do sistema, com dono — usado no painel do admin. */
    public static function allWithOwners(): array
    {
        $rows = Database::connection()->query(
            'SELECT s.id, s.db_name, s.created_at, u.id AS owner_id, u.name AS owner_name, u.email AS owner_email
             FROM schemas_criados s
             INNER JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC',
        )->fetchAll();

        return array_map(SchemaWithOwner::fromRow(...), $rows);
    }

    public static function countAll(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM schemas_criados')->fetchColumn();
    }

    public static function nameTaken(string $dbName): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM schemas_criados WHERE db_name = ?');
        $stmt->execute([$dbName]);

        return $stmt->fetch() !== false;
    }

    public static function create(int $userId, string $dbName): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO schemas_criados (user_id, db_name) VALUES (?, ?)',
        );
        $stmt->execute([$userId, $dbName]);
    }

    public static function findOwned(string $dbName, int $userId): ?Schema
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, db_name, created_at FROM schemas_criados WHERE db_name = ? AND user_id = ?',
        );
        $stmt->execute([$dbName, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : Schema::fromRow($row);
    }

    public static function findByName(string $dbName): ?Schema
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, db_name, created_at FROM schemas_criados WHERE db_name = ?',
        );
        $stmt->execute([$dbName]);
        $row = $stmt->fetch();

        return $row === false ? null : Schema::fromRow($row);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM schemas_criados WHERE id = ?');
        $stmt->execute([$id]);
    }
}
