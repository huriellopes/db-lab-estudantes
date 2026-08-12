<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Support\Role;

final class User
{
    public static function find(int $id): ?UserEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : UserEntity::fromRow($row);
    }

    public static function findByEmail(string $email): ?UserEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : UserEntity::fromRow($row);
    }

    public static function emailExists(string $email): bool
    {
        return self::findByEmail($email) !== null;
    }

    public static function mysqlLoginExists(string $mysqlLogin): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE mysql_login = ?');
        $stmt->execute([$mysqlLogin]);

        return $stmt->fetch() !== false;
    }

    /** @return list<UserEntity> Todos os usuários, opcionalmente filtrados por papel. */
    public static function all(?Role $role = null): array
    {
        if ($role !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM users WHERE role = ? ORDER BY created_at DESC',
            );
            $stmt->execute([$role->value]);

            return array_map(UserEntity::fromRow(...), $stmt->fetchAll());
        }

        $rows = Database::connection()
            ->query('SELECT * FROM users ORDER BY created_at DESC')
            ->fetchAll();

        return array_map(UserEntity::fromRow(...), $rows);
    }

    public static function countByRole(Role $role): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $stmt->execute([$role->value]);

        return (int) $stmt->fetchColumn();
    }

    public static function create(string $name, string $email, string $passwordHash, Role $role): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, mysql_login) VALUES (?, ?, ?, ?, ?)',
        );
        // mysql_login definitivo é preenchido depois, pois depende do id gerado aqui.
        $stmt->execute([$name, $email, $passwordHash, $role->value, 'pending']);

        return (int) $pdo->lastInsertId();
    }

    public static function setMysqlLogin(int $id, string $mysqlLogin): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET mysql_login = ? WHERE id = ?');
        $stmt->execute([$mysqlLogin, $id]);
    }

    public static function updateProfile(int $id, string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    /** Usado pelo professor/admin ao editar dados de outra pessoa (nome e e-mail). */
    public static function updateAccount(int $id, string $name, string $email): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
    }

    public static function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function updateRole(int $id, Role $role): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role->value, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
