<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;

/** GET /professor/alunos/{id}/editar. */
final class EditStudentAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $this->render('professor/students/edit', [
            'pageTitle' => 'Editar aluno',
            'student' => $this->findStudentOrFail((int) $params['id']),
        ]);
    }
}
