<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\Entities\StudentSummary;
use App\Models\Entities\User;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Support\Role;

/** Gestão de contas de alunos, disponível para professores e para o admin. GET /professor/alunos. */
final class IndexStudentsAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $students = array_map(
            static fn (User $student): StudentSummary => StudentSummary::fromUser(
                $student,
                count(SchemaRecord::allForUser($student->id)),
            ),
            UserModel::all(Role::Aluno),
        );

        $this->render('professor/students/index', [
            'pageTitle' => 'Gerenciar alunos',
            'students' => $students,
        ]);
    }
}
