<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User as UserModel;
use App\Services\UserManager;
use App\Support\RegistrationValidator;
use App\Support\Role;
use Throwable;

/** POST /admin/usuarios. */
final class StoreAdminUserAction extends Action
{
    public function __invoke(array $params = []): void
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
            UserModel::isLoginTaken($username),
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
}
