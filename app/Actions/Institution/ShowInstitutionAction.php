<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Core\View;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;

/** GET /admin/instituicoes/{id}. */
final class ShowInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $institution = Institution::find((int) ($params['id'] ?? 0));
        if ($institution === null) {
            http_response_code(404);
            echo View::render('errors/404');

            return;
        }

        $members = InstitutionMember::forInstitution($institution->id);

        $this->render('admin/institution', [
            'pageTitle' => $institution->name,
            'institution' => $institution,
            'professors' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'professor')),
            'students' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'aluno')),
            'classes' => SchoolClass::forInstitutions([$institution->id]),
        ]);
    }
}
