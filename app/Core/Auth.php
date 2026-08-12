<?php

namespace App\Core;

use App\Support\Policy;

class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION['user']['id'] : null;
    }

    /** @return array{id:int,name:string,email:string,role:string,mysql_login:string}|null */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'mysql_login' => $user['mysql_login'],
        ];
    }

    /** Atualiza os dados da sessão sem exigir novo login (usado após editar o próprio perfil). */
    public static function refresh(array $user): void
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
