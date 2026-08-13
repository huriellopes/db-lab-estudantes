<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;

/** GET /admin/usuarios/{id}/editar. */
final class EditAdminUserAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/user_edit', [
            'pageTitle' => 'Editar usuário',
            'user' => $this->findManageableUserOrFail((int) $params['id']),
        ]);
    }
}
