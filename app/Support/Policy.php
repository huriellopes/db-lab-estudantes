<?php

namespace App\Support;

/**
 * Regras de autorização, como funções puras sobre o array de usuário da sessão
 * (['id'=>..., 'name'=>..., 'role'=>..., 'mysql_login'=>...]). Sem I/O, fácil de testar.
 */
class Policy
{
    public const ROLE_ALUNO = 'aluno';
    public const ROLE_PROFESSOR = 'professor';
    public const ROLE_ADMIN = 'admin';

    public static function isAdmin(?array $user): bool
    {
        return ($user['role'] ?? null) === self::ROLE_ADMIN;
    }

    public static function isProfessor(?array $user): bool
    {
        return ($user['role'] ?? null) === self::ROLE_PROFESSOR;
    }

    public static function isAluno(?array $user): bool
    {
        return ($user['role'] ?? null) === self::ROLE_ALUNO;
    }

    /** Professor e admin podem ver/editar/excluir contas de alunos. */
    public static function canManageStudents(?array $user): bool
    {
        return self::isProfessor($user) || self::isAdmin($user);
    }

    /** Só o admin gerencia qualquer usuário (aluno, professor ou outro admin) e papéis. */
    public static function canManageAllUsers(?array $user): bool
    {
        return self::isAdmin($user);
    }

    /** Só o admin vê/exclui schemas de qualquer pessoa no sistema. */
    public static function canManageAllSchemas(?array $user): bool
    {
        return self::isAdmin($user);
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, [self::ROLE_ALUNO, self::ROLE_PROFESSOR, self::ROLE_ADMIN], true);
    }
}
