<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Institution;
use App\Models\InstitutionMember;

/** GET /admin/instituicoes. */
final class IndexInstitutionsAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/institutions', [
            'pageTitle' => 'Instituições',
            'institutions' => Institution::all(),
            'studentsWithoutInstitution' => InstitutionMember::studentsWithoutInstitution(),
        ]);
    }
}
