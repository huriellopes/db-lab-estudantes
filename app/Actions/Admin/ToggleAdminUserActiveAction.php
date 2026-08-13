<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Services\UserManager;
use Throwable;

/** POST /admin/usuarios/{id}/status. */
final class ToggleAdminUserActiveAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
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
}
