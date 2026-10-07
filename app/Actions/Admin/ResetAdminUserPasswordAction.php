<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Services\UserManager;
use App\Support\PasswordPolicy;
use Throwable;

/** POST /admin/usuarios/{id}/senha. */
final class ResetAdminUserPasswordAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        $passwordError = PasswordPolicy::validate($newPassword, $target->mysqlLogin, $target->email);
        if ($passwordError !== null) {
            $this->respond(false, $passwordError, '/admin/usuarios');
        }

        try {
            UserManager::resetPassword($target, $newPassword);
            $this->respond(true, "Senha de {$target->name} atualizada.", '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar a senha', $e), '/admin/usuarios');
        }
    }
}
