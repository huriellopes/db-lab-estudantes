<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quem vê e quem edita uma turma (ver App\Services\ClassManager e app/Actions/SchoolClass).
 * Ver: admin; professor da instituição da turma; aluno só se estiver nela. Editar: admin e os
 * professores responsáveis pela turma.
 */
final class ClassAccess
{
    /** @param list<int> $viewerInstitutionIds */
    public static function canView(Role $role, array $viewerInstitutionIds, int $classInstitutionId, bool $isMember): bool
    {
        return match ($role) {
            Role::Admin => true,
            Role::Professor => in_array($classInstitutionId, $viewerInstitutionIds, true),
            Role::Aluno => $isMember,
        };
    }

    /** @param list<int> $responsibleIds */
    public static function canEdit(Role $role, int $viewerId, array $responsibleIds): bool
    {
        return $role === Role::Admin || ($role === Role::Professor && in_array($viewerId, $responsibleIds, true));
    }
}
