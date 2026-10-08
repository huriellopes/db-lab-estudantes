<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quem enxerga qual aluno em /professor/alunos (e nas ações sobre ele): admin vê todos;
 * professor só os de uma instituição em comum; aluno sem instituição só o admin vê.
 */
final class InstitutionScope
{
    /** @param list<int> $viewerInstitutionIds */
    public static function canSeeStudent(Role $viewerRole, array $viewerInstitutionIds, ?int $studentInstitutionId): bool
    {
        return match ($viewerRole) {
            Role::Admin => true,
            Role::Professor => $studentInstitutionId !== null && in_array($studentInstitutionId, $viewerInstitutionIds, true),
            default => false,
        };
    }
}
