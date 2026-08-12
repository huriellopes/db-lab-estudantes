<?php

namespace App\Models;

use App\Core\Database;

/**
 * Registro (na tabela schemas_criados) de quem é dono de cada database criado.
 * A criação/remoção do database em si é responsabilidade de App\Services\SchemaProvisioner.
 */
class SchemaRecord
{
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, db_name, created_at FROM schemas_criados WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** Todos os schemas do sistema, com nome/e-mail do dono — usado no painel do admin. */
    public static function allWithOwners(): array
    {
        return Database::connection()->query(
            'SELECT s.id, s.db_name, s.created_at, u.id AS owner_id, u.name AS owner_name, u.email AS owner_email
             FROM schemas_criados s
             INNER JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC'
        )->fetchAll();
    }

    public static function countAll(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM schemas_criados')->fetchColumn();
    }

    public static function nameTaken(string $dbName): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM schemas_criados WHERE db_name = ?');
        $stmt->execute([$dbName]);

        return (bool) $stmt->fetch();
    }

    public static function create(int $userId, string $dbName): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO schemas_criados (user_id, db_name) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $dbName]);
    }

    public static function findOwned(string $dbName, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM schemas_criados WHERE db_name = ? AND user_id = ?'
        );
        $stmt->execute([$dbName, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByName(string $dbName): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM schemas_criados WHERE db_name = ?');
        $stmt->execute([$dbName]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM schemas_criados WHERE id = ?');
        $stmt->execute([$id]);
    }
}
