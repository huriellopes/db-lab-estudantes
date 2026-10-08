<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\User;
use App\Models\InstitutionMember;
use App\Models\User as UserModel;
use App\Support\InstitutionScope;
use App\Support\Role;

/**
 * Base comum das Actions de gestão de aluno (ver app/Actions/Student/*) — todas exigem
 * App\Core\Auth::requireProfessorOrAdmin() e a maioria precisa localizar o aluno-alvo pelo
 * `id` da rota, validando que é mesmo um aluno (não um professor/admin cujo id a pessoa
 * tenha adivinhado). Herdar daqui em vez de duplicar findStudentOrFail() em cada Action
 * evita esse check de segurança divergir entre elas.
 */
abstract class StudentAction extends Action
{
    protected function findStudentOrFail(int $id): User
    {
        $student = UserModel::find($id);

        // Professor só pode agir sobre contas de aluno, mesmo se souber o id de outra pessoa —
        // e só de alunos de uma instituição em comum (fora disso, "não encontrado": não revela que existe).
        if ($student === null || $student->role !== Role::Aluno || !$this->canSee($student)) {
            $this->respond(false, 'Aluno não encontrado.', '/professor/alunos');
        }

        return $student;
    }

    protected function canSee(User $student): bool
    {
        $viewer = Auth::user();

        return $viewer !== null && InstitutionScope::canSeeStudent(
            $viewer->role,
            InstitutionMember::institutionIdsOf($viewer->id),
            InstitutionMember::institutionOfStudent($student->id),
        );
    }
}
