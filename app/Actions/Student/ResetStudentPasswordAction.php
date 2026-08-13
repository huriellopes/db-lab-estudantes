<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Services\UserManager;
use Throwable;

/** POST /professor/alunos/{id}/senha. */
final class ResetStudentPasswordAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            $this->respond(false, 'A nova senha deve ter pelo menos 6 caracteres.', '/professor/alunos');
        }

        try {
            UserManager::resetPassword($student, $newPassword);
            $this->respond(true, "Senha de {$student->name} atualizada.", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar a senha', $e), '/professor/alunos');
        }
    }
}
