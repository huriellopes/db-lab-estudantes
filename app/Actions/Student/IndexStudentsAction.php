<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\Entities\StudentSummary;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Support\InstitutionScope;
use App\Support\Role;

/**
 * Gestão de contas de alunos. GET /professor/alunos[?instituicao=<id>|sem]. Professor vê só os
 * alunos das instituições dele; admin vê todos (e pode filtrar os "sem instituição").
 */
final class IndexStudentsAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $viewer = Auth::user();
        $filter = (string) ($_GET['instituicao'] ?? '');

        $byStudent = InstitutionMember::studentInstitutions();
        $visible = array_flip(self::visibleStudentIds($viewer->role, $viewer->id, $filter));

        $students = [];
        foreach (UserModel::all(Role::Aluno) as $student) {
            if (isset($visible[$student->id])) {
                $students[] = StudentSummary::fromUser($student, count(SchemaRecord::allForUser($student->id)), $byStudent[$student->id]['name'] ?? null);
            }
        }

        $myIds = $viewer->role === Role::Admin ? null : InstitutionMember::institutionIdsOf($viewer->id);
        $options = array_values(array_filter(Institution::all(), static fn ($i): bool => $myIds === null || in_array($i->id, $myIds, true)));

        $this->render('professor/students/index', [
            'pageTitle' => 'Gerenciar alunos',
            'students' => $students,
            'institutions' => $options,
            'filter' => $filter,
            'isAdmin' => $viewer->role === Role::Admin,
        ]);
    }

    /**
     * Ids dos alunos que essa pessoa enxerga, já com o filtro aplicado ('' = todos os visíveis,
     * 'sem' = sem instituição (só admin), '<id>' = de uma instituição).
     *
     * @return list<int>
     */
    public static function visibleStudentIds(Role $role, int $viewerId, ?string $filter): array
    {
        $byStudent = InstitutionMember::studentInstitutions();
        $mine = $role === Role::Admin ? [] : InstitutionMember::institutionIdsOf($viewerId);

        $ids = [];
        foreach (UserModel::all(Role::Aluno) as $student) {
            $institutionId = $byStudent[$student->id]['id'] ?? null;
            if (!InstitutionScope::canSeeStudent($role, $mine, $institutionId)) {
                continue;
            }
            if ($filter === 'sem' && $institutionId !== null) {
                continue;
            }
            if ($filter !== null && $filter !== '' && $filter !== 'sem' && $institutionId !== (int) $filter) {
                continue;
            }
            $ids[] = $student->id;
        }

        return $ids;
    }
}
