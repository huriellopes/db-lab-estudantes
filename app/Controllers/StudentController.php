<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\UserManager;
use App\Support\Policy;
use Throwable;

/** Gestão de contas de alunos, disponível para professores e para o admin. */
class StudentController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $students = array_map(static function (array $student): array {
            $student['schemas_count'] = count(SchemaRecord::allForUser((int) $student['id']));
            return $student;
        }, User::all(Policy::ROLE_ALUNO));

        $this->render('professor/students/index', [
            'pageTitle' => 'Gerenciar alunos',
            'students' => $students,
        ]);
    }

    public function edit(array $params): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        $this->render('professor/students/edit', [
            'pageTitle' => 'Editar aluno',
            'student' => $student,
            'errors' => [],
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'Informe um nome e e-mail válidos.', '/professor/alunos');
        }

        $existing = User::findByEmail($email);
        if ($existing && (int) $existing['id'] !== $student['id']) {
            $this->respond(false, 'Já existe uma conta com este e-mail.', '/professor/alunos');
        }

        User::updateAccount($student['id'], $name, $email);

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
            $this->respond(true, "Senha de {$student['name']} atualizada.", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível trocar a senha: ' . $e->getMessage(), '/professor/alunos');
        }
    }

    public function destroy(array $params): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        try {
            UserManager::deleteCompletely($student);
            $this->respond(true, "Conta de {$student['name']} removida.", '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível excluir a conta: ' . $e->getMessage(), '/professor/alunos');
        }
    }

    private function findStudentOrFail(int $id): array
    {
        $student = User::find($id);

        // Professor só pode agir sobre contas de aluno, mesmo se souber o id de outra pessoa.
        if (!$student || $student['role'] !== Policy::ROLE_ALUNO) {
            $this->respond(false, 'Aluno não encontrado.', '/professor/alunos');
        }

        return $student;
    }
}
