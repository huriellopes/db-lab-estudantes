<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Entities\User;

/**
 * Regras de autorização, como funções puras sobre o usuário autenticado (ou null, se
 * visitante). Sem I/O, fácil de testar.
 */
final class Policy
{
    public static function isAdmin(?AuthenticatedUser $user): bool
    {
        return $user?->role === Role::Admin;
    }

    public static function isProfessor(?AuthenticatedUser $user): bool
    {
        return $user?->role === Role::Professor;
    }

    public static function isAluno(?AuthenticatedUser $user): bool
    {
        return $user?->role === Role::Aluno;
    }

    /** Professor e admin podem ver/editar/excluir contas de alunos. */
    public static function canManageStudents(?AuthenticatedUser $user): bool
    {
        return self::isProfessor($user) || self::isAdmin($user);
    }

    /** Só o admin gerencia qualquer usuário (aluno, professor ou outro admin) e papéis. */
    public static function canManageAllUsers(?AuthenticatedUser $user): bool
    {
        return self::isAdmin($user);
    }

    /** Só o admin vê/exclui schemas de qualquer pessoa no sistema. */
    public static function canManageAllSchemas(?AuthenticatedUser $user): bool
    {
        return self::isAdmin($user);
    }

    /**
     * "Entrar como" (ver App\Core\Auth::impersonate): só admin, só em conta ativa de professor
     * ou aluno, nunca na própria nem encadeando uma impersonação dentro de outra.
     */
    public static function canImpersonate(?AuthenticatedUser $actor, bool $actorIsImpersonating, User $target): bool
    {
        return self::isAdmin($actor)
            && !$actorIsImpersonating
            && $target->id !== $actor->id
            && $target->active
            && in_array($target->role, [Role::Professor, Role::Aluno], true);
    }
}
