<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Services\UserManager;
use Throwable;

/** POST /admin/usuarios/{id}/senha. */
final class ResetAdminUserPasswordAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
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
}
