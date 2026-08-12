<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Entities\StudentSummary;
use App\Models\Entities\User;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Services\UserManager;
use App\Support\ProfileFields;
use App\Support\Role;
use Throwable;

/** Gestão de contas de alunos, disponível para professores e para o admin. */
final class StudentController extends Controller
{
    public function index(array $params = []): void
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

    public function edit(array $params): void
    {
        Auth::requireProfessorOrAdmin();

        $this->render('professor/students/edit', [
            'pageTitle' => 'Editar aluno',
            'student' => $this->findStudentOrFail((int) $params['id']),
        ]);
    }

    public function update(array $params): void
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

        $this->respond(true, 'Dados do aluno atualizados.', '/professor/alunos');
    }

    public function resetPassword(array $params): void
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
            $this->respond(false, 'Não foi possível trocar a senha: ' . $e->getMessage(), '/professor/alunos');
        }
    }

    public function toggleActive(array $params): void
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
            $this->respond(false, 'Não foi possível atualizar o status: ' . $e->getMessage(), '/professor/alunos');
        }
    }

    public function destroy(array $params): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        try {
            UserManager::softDelete($student);
            $this->respond(true, "Conta de {$student->name} excluída (um admin pode restaurar na lixeira).", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível excluir a conta: ' . $e->getMessage(), '/professor/alunos');
        }
    }

    private function findStudentOrFail(int $id): User
    {
        $student = UserModel::find($id);

        // Professor só pode agir sobre contas de aluno, mesmo se souber o id de outra pessoa.
        if ($student === null || $student->role !== Role::Aluno) {
            $this->respond(false, 'Aluno não encontrado.', '/professor/alunos');
        }

        return $student;
    }
}
