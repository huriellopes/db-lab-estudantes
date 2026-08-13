<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Services\UserManager;
use Throwable;

/** POST /professor/alunos/{id}/status. */
final class ToggleStudentActiveAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        try {
            UserManager::setActive($student, !$student->active);
            $message = $student->active
                ? "Conta de {$student->name} desativada."
                : "Conta de {$student->name} reativada.";
            $this->respond(true, $message, '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('atualizar o status', $e), '/professor/alunos');
        }
    }
}
