<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Support\Role;

/**
 * Exclusão é sempre soft delete (deleted_at), como o SoftDeletes do Laravel: os métodos
 * de leitura abaixo (find, findByEmail, all, ...) excluem linhas com deleted_at
 * preenchido por padrão. Use trashed()/findTrashed() para enxergar quem foi excluído.
 */
final class User
{
    public static function find(int $id): ?UserEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : UserEntity::fromRow($row);
    }

    public static function findByEmail(string $email): ?UserEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : UserEntity::fromRow($row);
    }

    /** Login por e-mail OU pelo username (mysql_login) escolhido no cadastro. */
    public static function findByEmailOrUsername(string $identifier): ?UserEntity
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE (email = ? OR mysql_login = ?) AND deleted_at IS NULL',
        );
        $stmt->execute([$identifier, $identifier]);
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

    /** @return list<UserEntity> Todos os usuários não excluídos, opcionalmente filtrados por papel. */
    public static function all(?Role $role = null): array
    {
        if ($role !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM users WHERE role = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            );
            $stmt->execute([$role->value]);

            return array_map(UserEntity::fromRow(...), $stmt->fetchAll());
        }

        $rows = Database::connection()
            ->query('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC')
            ->fetchAll();

        return array_map(UserEntity::fromRow(...), $rows);
    }

    /** Usado no painel do admin: todo mundo, menos outras contas admin. */
    public static function allManageable(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE deleted_at IS NULL AND role <> ? ORDER BY created_at DESC',
        );
        $stmt->execute([Role::Admin->value]);

        return array_map(UserEntity::fromRow(...), $stmt->fetchAll());
    }

    /** @return list<UserEntity> Contas excluídas (soft delete) — a "lixeira". */
    public static function trashed(): array
    {
        $rows = Database::connection()
            ->query('SELECT * FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')
            ->fetchAll();

        return array_map(UserEntity::fromRow(...), $rows);
    }

    public static function findTrashed(int $id): ?UserEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : UserEntity::fromRow($row);
    }

    public static function countByRole(Role $role): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE role = ? AND deleted_at IS NULL',
        );
        $stmt->execute([$role->value]);

        return (int) $stmt->fetchColumn();
    }

    public static function create(string $name, string $email, string $passwordHash, Role $role): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, mysql_login) VALUES (?, ?, ?, ?, ?)',
        );
        // mysql_login definitivo é preenchido logo em seguida, pelo próprio chamador.
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

    /** Derruba todas as sessões já abertas dessa conta (ver App\Core\Auth::enforceSession). */
    public static function bumpSessionVersion(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?');
        $stmt->execute([$id]);
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

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /** Chamado a cada login (normal ou via cookie "lembrar de mim") — exibido pro admin. */
    public static function touchLastLogin(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function softDelete(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function restore(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET deleted_at = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Apaga a linha de verdade — só para desfazer um cadastro que falhou no meio do
     * caminho (ver App\Services\UserManager::provisionNewUser). Nunca exposto a um
     * controller: a exclusão "normal" é sempre softDelete().
     */
    public static function forceDelete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
