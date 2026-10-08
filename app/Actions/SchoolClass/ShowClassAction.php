<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Core\View;
use App\Models\ClassMember;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;
use App\Services\ClassManager;
use App\Support\ClassAccess;

/** GET /turmas/{id}. Quem não pode ver recebe 404 (não revela que a turma existe). */
final class ShowClassAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $viewer = Auth::user();
        $class = SchoolClass::find((int) ($params['id'] ?? 0));

        if ($class === null || !ClassAccess::canView($viewer->role, InstitutionMember::institutionIdsOf($viewer->id), $class->institutionId, false)) {
            http_response_code(404);
            echo View::render('errors/404');

            return;
        }

        $members = ClassMember::forClass($class->id);

        $this->render('classes/show', [
            'pageTitle' => $class->name,
            'class' => $class,
            'canEdit' => ClassManager::canEdit($class, $viewer),
            'professors' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'professor')),
            'students' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'aluno')),
        ]);
    }
}
