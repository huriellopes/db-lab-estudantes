<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Entities\User;
use App\Support\AuthenticatedUser;
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

    public static function login(User $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = AuthenticatedUser::fromEntity($user);
    }

    /** Atualiza os dados da sessão sem exigir novo login (usado após editar o próprio perfil). */
    public static function refresh(User $user): void
    {
        if (self::check()) {
            self::login($user);
        }
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
