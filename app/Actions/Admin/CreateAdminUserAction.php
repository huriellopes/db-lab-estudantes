<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Support\Role;

/** Formulário de novo usuário. GET /admin/usuarios/novo. */
final class CreateAdminUserAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/user_create', [
            'pageTitle' => 'Novo usuário',
            'old' => ['name' => '', 'email' => '', 'username' => '', 'role' => Role::Aluno->value],
            'roles' => Role::cases(),
            'errors' => [],
        ]);
    }
}
