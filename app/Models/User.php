<?php

namespace App\Models;

use App\Core\Database;

class User
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public static function emailExists(string $email): bool
    {
        return self::findByEmail($email) !== null;
    }

    /** @return array[] Todos os usuários, opcionalmente filtrados por papel. */
    public static function all(?string $role = null): array
    {
        if ($role !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM users WHERE role = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$role]);

            return $stmt->fetchAll();
        }

        return Database::connection()
            ->query('SELECT * FROM users ORDER BY created_at DESC')
            ->fetchAll();
    }

    public static function countByRole(string $role): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $stmt->execute([$role]);

        return (int) $stmt->fetchColumn();
    }

    public static function create(string $name, string $email, string $passwordHash, string $role): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, mysql_login) VALUES (?, ?, ?, ?, ?)'
        );
        // mysql_login definitivo é preenchido depois, pois depende do id gerado aqui.
        $stmt->execute([$name, $email, $passwordHash, $role, 'pending']);

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

    public static function updateRole(int $id, string $role): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
