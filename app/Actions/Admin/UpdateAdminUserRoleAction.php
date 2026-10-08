<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\User as UserModel;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
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

        try {
            InstitutionManager::syncRoleChange($target, $role);
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), '/admin/usuarios');
        }

        UserModel::updateRole($target->id, $role);

        AuditLog::record('user.role_changed', 'user', $target->id, ['de' => $target->role->value, 'para' => $role->value]);
        $this->respond(true, "Papel de {$target->name} atualizado para {$role->label()}.", '/admin/usuarios');
    }
}
