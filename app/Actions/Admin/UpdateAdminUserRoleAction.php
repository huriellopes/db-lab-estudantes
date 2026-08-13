<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Models\User as UserModel;
use App\Support\Role;

/** POST /admin/usuarios/{id}/papel. */
final class UpdateAdminUserRoleAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
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
}
