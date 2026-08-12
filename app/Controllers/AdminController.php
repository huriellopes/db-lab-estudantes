<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\SchemaProvisioner;
use App\Services\UserManager;
use App\Support\Policy;
use App\Support\SchemaNameBuilder;
use Throwable;

/** Painel do super admin: controle total sobre usuários, papéis e schemas do lab inteiro. */
class AdminController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/index', [
            'pageTitle' => 'Administração',
            'stats' => [
                'alunos' => User::countByRole(Policy::ROLE_ALUNO),
                'professores' => User::countByRole(Policy::ROLE_PROFESSOR),
                'admins' => User::countByRole(Policy::ROLE_ADMIN),
                'schemas' => SchemaRecord::countAll(),
            ],
        ]);
    }

    public function users(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/users', [
            'pageTitle' => 'Usuários',
            'users' => User::all(),
            'roles' => [Policy::ROLE_ALUNO, Policy::ROLE_PROFESSOR, Policy::ROLE_ADMIN],
        ]);
    }

    public function edit(array $params): void
    {
        Auth::requireAdmin();

        $this->render('admin/user_edit', [
            'pageTitle' => 'Editar usuário',
            'user' => $this->findUserOrFail((int) $params['id']),
            'errors' => [],
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findUserOrFail((int) $params['id']);

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'Informe um nome e e-mail válidos.', '/admin/usuarios');
        }

        $existing = User::findByEmail($email);
        if ($existing && (int) $existing['id'] !== $target['id']) {
            $this->respond(false, 'Já existe uma conta com este e-mail.', '/admin/usuarios');
        }

        User::updateAccount($target['id'], $name, $email);

        $this->respond(true, 'Usuário atualizado.', '/admin/usuarios');
    }

    public function updateRole(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findUserOrFail((int) $params['id']);
        $role = (string) ($_POST['role'] ?? '');

        if ($target['id'] === Auth::id()) {
            $this->respond(false, 'Você não pode alterar seu próprio papel.', '/admin/usuarios');
        }
        if (!Policy::isValidRole($role)) {
            $this->respond(false, 'Papel inválido.', '/admin/usuarios');
        }

        User::updateRole($target['id'], $role);

        $this->respond(true, "Papel de {$target['name']} atualizado para {$role}.", '/admin/usuarios');
    }

    public function resetPassword(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findUserOrFail((int) $params['id']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            $this->respond(false, 'A nova senha deve ter pelo menos 6 caracteres.', '/admin/usuarios');
        }

        try {
            UserManager::resetPassword($target, $newPassword);
            $this->respond(true, "Senha de {$target['name']} atualizada.", '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível trocar a senha: ' . $e->getMessage(), '/admin/usuarios');
        }
    }

    public function destroy(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findUserOrFail((int) $params['id']);

        if ($target['id'] === Auth::id()) {
            $this->respond(false, 'Você não pode excluir a própria conta.', '/admin/usuarios');
        }

        try {
            UserManager::deleteCompletely($target);
            $this->respond(true, "Conta de {$target['name']} removida.", '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível excluir a conta: ' . $e->getMessage(), '/admin/usuarios');
        }
    }

    public function schemas(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/schemas', [
            'pageTitle' => 'Todos os schemas',
            'schemas' => SchemaRecord::allWithOwners(),
            'pmaUrl' => rtrim(Config::get('PMA_URL', 'http://localhost:8081'), '/'),
        ]);
    }

    public function destroySchema(array $params = []): void
    {
        Auth::requireAdmin();

        $dbName = trim($_POST['db_name'] ?? '');

        if (!SchemaNameBuilder::isValidDbName($dbName)) {
            $this->respond(false, 'Schema inválido.', '/admin/schemas');
        }

        $record = SchemaRecord::findByName($dbName);
        if (!$record) {
            $this->respond(false, 'Schema não encontrado.', '/admin/schemas');
        }

        try {
            SchemaProvisioner::dropDatabase($dbName);
            SchemaRecord::delete($record['id']);
            $this->respond(true, "Schema \"{$dbName}\" removido.", '/admin/schemas');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível remover o schema: ' . $e->getMessage(), '/admin/schemas');
        }
    }

    private function findUserOrFail(int $id): array
    {
        $user = User::find($id);

        if (!$user) {
            $this->respond(false, 'Usuário não encontrado.', '/admin/usuarios');
        }

        return $user;
    }
}
