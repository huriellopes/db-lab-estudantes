<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Entities\User;
use App\Support\AuthenticatedUser;
use App\Support\Crypto;
use App\Support\Policy;

final class Auth
{
    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()?->id;
    }

    public static function user(): ?AuthenticatedUser
    {
        $user = $_SESSION['user'] ?? null;

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * @param string|null $plainPassword Quando informada (login de verdade, não um
     *                                   refresh() de dados), fica cacheada (criptografada, ver Crypto) na sessão —
     *                                   é o que permite o console SQL do dashboard abrir uma conexão MySQL como a
     *                                   própria pessoa (ver Database::connectAs / mysqlPassword()) sem pedir a senha
     *                                   de novo a cada comando.
     */
    public static function login(User $user, ?string $plainPassword = null): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = AuthenticatedUser::fromEntity($user);
        if ($plainPassword !== null) {
            $_SESSION['mysql_password_enc'] = Crypto::encrypt($plainPassword);
        }
    }

    /** Atualiza os dados da sessão sem exigir novo login (usado após editar o próprio perfil). */
    public static function refresh(User $user): void
    {
        if (self::check()) {
            self::login($user);
        }
    }

    /** Chamado depois que a pessoa troca a própria senha, pra manter o cache em dia. */
    public static function refreshMysqlPassword(string $plainPassword): void
    {
        $_SESSION['mysql_password_enc'] = Crypto::encrypt($plainPassword);
    }

    /**
     * Senha MySQL em texto puro da pessoa logada, descriptografada na hora — null se
     * nunca foi cacheada (ex.: sessão aberta antes dessa feature existir; a pessoa
     * precisa só logar de novo) ou se o valor guardado estiver corrompido.
     */
    public static function mysqlPassword(): ?string
    {
        $encrypted = $_SESSION['mysql_password_enc'] ?? null;

        return is_string($encrypted) ? Crypto::decrypt($encrypted) : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function isAdmin(): bool
    {
        return Policy::isAdmin(self::user());
    }

    public static function isProfessor(): bool
    {
        return Policy::isProfessor(self::user());
    }

    public static function isAluno(): bool
    {
        return Policy::isAluno(self::user());
    }

    public static function canManageStudents(): bool
    {
        return Policy::canManageStudents(self::user());
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireProfessorOrAdmin(): void
    {
        self::requireLogin();

        if (!self::canManageStudents()) {
            self::forbidden();
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            self::forbidden();
        }
    }

    private static function forbidden(): never
    {
        http_response_code(403);
        echo View::render('errors/403');
        exit;
    }
}
