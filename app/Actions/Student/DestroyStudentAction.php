<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\UserManager;
use Throwable;

/** POST /professor/alunos/{id}/excluir. */
final class DestroyStudentAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        try {
            UserManager::softDelete($student);
            AuditLog::record('student.deleted', 'user', $student->id, ['email' => $student->email]);
            $this->respond(true, "Conta de {$student->name} excluída (um admin pode restaurar na lixeira).", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/professor/alunos');
        }
    }
}
