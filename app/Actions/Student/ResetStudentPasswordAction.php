<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\UserManager;
use App\Support\PasswordPolicy;
use Throwable;

/** POST /professor/alunos/{id}/senha. */
final class ResetStudentPasswordAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        $passwordError = PasswordPolicy::validate($newPassword, $student->mysqlLogin, $student->email);
        if ($passwordError !== null) {
            $this->respond(false, $passwordError, '/professor/alunos');
        }

        try {
            UserManager::resetPassword($student, $newPassword);
            AuditLog::record('student.password_reset', 'user', $student->id);
            $this->respond(true, "Senha de {$student->name} atualizada.", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar a senha', $e), '/professor/alunos');
        }
    }
}
