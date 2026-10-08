<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;
use App\Support\Role;

/** Turmas visíveis (professor: das instituições dele; admin: todas). GET /turmas. */
final class IndexClassesAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $viewer = Auth::user();
        $isAdmin = $viewer->role === Role::Admin;
        $myIds = $isAdmin ? null : InstitutionMember::institutionIdsOf($viewer->id);

        $classes = $isAdmin ? SchoolClass::all() : SchoolClass::forInstitutions($myIds);
        $byInstitution = [];
        foreach ($classes as $class) {
            $byInstitution[$class->institutionName][] = $class;
        }

        $this->render('classes/index', [
            'pageTitle' => 'Turmas',
            'byInstitution' => $byInstitution,
            'institutions' => array_values(array_filter(Institution::all(), static fn ($i): bool => $myIds === null || in_array($i->id, $myIds, true))),
        ]);
    }
}
