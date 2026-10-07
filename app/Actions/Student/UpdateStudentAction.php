<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\User as UserModel;
use App\Support\ProfileFields;

/** POST /professor/alunos/{id}. */
final class UpdateStudentAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!ProfileFields::isValidName($name) || !ProfileFields::isValidEmail($email)) {
            $this->respond(false, 'Informe um nome e e-mail válidos.', '/professor/alunos');
        }

        $existing = UserModel::findByEmail($email);
        if ($existing !== null && $existing->id !== $student->id) {
            $this->respond(false, 'Já existe uma conta com este e-mail.', '/professor/alunos');
        }

        UserModel::updateAccount($student->id, $name, $email);

        AuditLog::record('student.updated', 'user', $student->id);
        $this->respond(true, 'Dados do aluno atualizados.', '/professor/alunos');
    }
}
