<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\Entities\SchemaWithOwner;
use App\Models\Entities\User;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Services\SchemaProvisioner;
use App\Services\UserManager;
use App\Support\AdminStats;
use App\Support\ProfileFields;
use App\Support\RegistrationValidator;
use App\Support\Role;
use App\Support\SchemaNameBuilder;
use App\Support\TableFilter;
use App\Support\TableQuery;
use Throwable;

/** Painel do super admin: controle total sobre usuários, papéis e schemas do lab inteiro. */
final class AdminController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/index', [
            'pageTitle' => 'Administração',
            'stats' => new AdminStats(
                alunos: UserModel::countByRole(Role::Aluno),
                professores: UserModel::countByRole(Role::Professor),
                admins: UserModel::countByRole(Role::Admin),
                schemas: SchemaRecord::countAll(),
            ),
        ]);
    }

    public function users(array $params = []): void
    {
        Auth::requireAdmin();

        $query = TableQuery::fromParams($_GET, ['name', 'email', 'role', 'status', 'created']);
        $paginator = TableFilter::paginate(
            UserModel::allManageable(),
            $query,
            searchText: static fn (User $u): string => "{$u->name} {$u->email} {$u->mysqlLogin} {$u->role->label()}",
            sortAccessors: [
                'name' => static fn (User $u): string => mb_strtolower($u->name),
                'email' => static fn (User $u): string => mb_strtolower($u->email),
                'role' => static fn (User $u): string => $u->role->label(),
                'status' => static fn (User $u): int => $u->active ? 1 : 0,
                'created' => static fn (User $u): int => $u->createdAt->getTimestamp(),
            ],
            perPage: 10,
        );

        $this->render('admin/users', [
            'pageTitle' => 'Usuários',
            'paginator' => $paginator,
            'query' => $query,
            'roles' => Role::cases(),
        ]);
    }

    public function create(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/user_create', [
            'pageTitle' => 'Novo usuário',
            'old' => ['name' => '', 'email' => '', 'username' => '', 'role' => Role::Aluno->value],
            'roles' => Role::cases(),
            'errors' => [],
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requireAdmin();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $roleInput = (string) ($_POST['role'] ?? '');
        $old = compact('name', 'email', 'username') + ['role' => $roleInput];

        $errors = RegistrationValidator::validate(
            $name,
            $email,
            $username,
            $password,
            $passwordConfirm,
            UserModel::emailExists($email),
            UserModel::mysqlLoginExists($username),
        );

        $role = Role::tryFrom($roleInput);
        if ($role === null) {
            $errors[] = 'Selecione um perfil válido.';
        }

        if (!$errors) {
            try {
                UserManager::provisionNewUser($name, $email, $username, $password, $role);

                $this->respond(true, "Usuário \"{$name}\" criado.", '/admin/usuarios');
            } catch (Throwable $e) {
                $errors[] = $this->genericError('criar o usuário', $e);
            }
        }

        $this->render('admin/user_create', [
            'pageTitle' => 'Novo usuário',
            'old' => $old,
            'roles' => Role::cases(),
            'errors' => $errors,
        ]);
    }

    public function edit(array $params): void
    {
        Auth::requireAdmin();

        $this->render('admin/user_edit', [
            'pageTitle' => 'Editar usuário',
            'user' => $this->findManageableUserOrFail((int) $params['id']),
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!ProfileFields::isValidName($name) || !ProfileFields::isValidEmail($email)) {
            $this->respond(false, 'Informe um nome e e-mail válidos.', '/admin/usuarios');
        }

        $existing = UserModel::findByEmail($email);
        if ($existing !== null && $existing->id !== $target->id) {
            $this->respond(false, 'Já existe uma conta com este e-mail.', '/admin/usuarios');
        }

        UserModel::updateAccount($target->id, $name, $email);

        $this->respond(true, 'Usuário atualizado.', '/admin/usuarios');
    }

    public function updateRole(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);
        $role = Role::tryFrom((string) ($_POST['role'] ?? ''));

        if ($role === null) {
            $this->respond(false, 'Papel inválido.', '/admin/usuarios');
        }

        UserModel::updateRole($target->id, $role);

        $this->respond(true, "Papel de {$target->name} atualizado para {$role->label()}.", '/admin/usuarios');
    }

    public function toggleActive(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);

        try {
            UserManager::setActive($target, !$target->active);
            $message = $target->active
                ? "Conta de {$target->name} desativada."
                : "Conta de {$target->name} reativada.";
            $this->respond(true, $message, '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('atualizar o status', $e), '/admin/usuarios');
        }
    }

    public function resetPassword(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            $this->respond(false, 'A nova senha deve ter pelo menos 6 caracteres.', '/admin/usuarios');
        }

        try {
            UserManager::resetPassword($target, $newPassword);
            $this->respond(true, "Senha de {$target->name} atualizada.", '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar a senha', $e), '/admin/usuarios');
        }
    }

    public function destroy(array $params): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);

        try {
            UserManager::softDelete($target);
            $this->respond(true, "Conta de {$target->name} excluída (dá pra restaurar na lixeira).", '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/admin/usuarios');
        }
    }

    public function trash(array $params = []): void
    {
        Auth::requireAdmin();

        $query = TableQuery::fromParams($_GET, ['name', 'email', 'role', 'deleted']);
        $paginator = TableFilter::paginate(
            UserModel::trashed(),
            $query,
            searchText: static fn (User $u): string => "{$u->name} {$u->email} {$u->mysqlLogin} {$u->role->label()}",
            sortAccessors: [
                'name' => static fn (User $u): string => mb_strtolower($u->name),
                'email' => static fn (User $u): string => mb_strtolower($u->email),
                'role' => static fn (User $u): string => $u->role->label(),
                'deleted' => static fn (User $u): int => $u->deletedAt?->getTimestamp() ?? 0,
            ],
            perPage: 10,
        );

        $this->render('admin/trash', [
            'pageTitle' => 'Lixeira',
            'paginator' => $paginator,
            'query' => $query,
        ]);
    }

    public function restore(array $params): void
    {
        Auth::requireAdmin();

        $target = UserModel::findTrashed((int) $params['id']);
        if ($target === null) {
            $this->respond(false, 'Usuário não encontrado na lixeira.', '/admin/usuarios/lixeira');
        }

        try {
            UserManager::restore($target);
            $this->respond(true, "Conta de {$target->name} restaurada.", '/admin/usuarios/lixeira');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('restaurar a conta', $e), '/admin/usuarios/lixeira');
        }
    }

    public function schemas(array $params = []): void
    {
        Auth::requireAdmin();

        $pmaUrl = Config::get('PMA_URL');
        $allSchemas = SchemaRecord::allWithOwners();

        $query = TableQuery::fromParams($_GET, ['schema', 'owner', 'created']);
        $paginator = TableFilter::paginate(
            $allSchemas,
            $query,
            searchText: static fn (SchemaWithOwner $s): string => "{$s->dbName} {$s->ownerName} {$s->ownerEmail}",
            sortAccessors: [
                'schema' => static fn (SchemaWithOwner $s): string => mb_strtolower($s->dbName),
                'owner' => static fn (SchemaWithOwner $s): string => mb_strtolower($s->ownerName),
                'created' => static fn (SchemaWithOwner $s): int => $s->createdAt->getTimestamp(),
            ],
            perPage: 10,
        );

        $this->render('admin/schemas', [
            'pageTitle' => 'Todos os schemas',
            'paginator' => $paginator,
            'query' => $query,
            'totalSchemas' => count($allSchemas),
            'pmaUrl' => $pmaUrl !== null ? rtrim($pmaUrl, '/') : null,
        ]);
    }

    public function destroySchema(array $params = []): void
    {
        Auth::requireAdmin();

        $dbName = trim((string) ($_POST['db_name'] ?? ''));

        if (!SchemaNameBuilder::isValidDbName($dbName)) {
            $this->respond(false, 'Schema inválido.', '/admin/schemas');
        }

        $record = SchemaRecord::findByName($dbName);
        if ($record === null) {
            $this->respond(false, 'Schema não encontrado.', '/admin/schemas');
        }

        try {
            SchemaProvisioner::dropDatabase($dbName);
            SchemaRecord::delete($record->id);
            $this->respond(true, "Schema \"{$dbName}\" removido.", '/admin/schemas');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/admin/schemas');
        }
    }

    /** Bloqueia ações sobre a própria conta e sobre outras contas admin (não listadas neste painel). */
    private function findManageableUserOrFail(int $id): User
    {
        $user = UserModel::find($id);

        if ($user === null || $user->role === Role::Admin) {
            $this->respond(false, 'Usuário não encontrado.', '/admin/usuarios');
        }
        if ($user->id === Auth::id()) {
            $this->respond(false, 'Você não pode gerenciar a própria conta por aqui.', '/admin/usuarios');
        }

        return $user;
    }
}
