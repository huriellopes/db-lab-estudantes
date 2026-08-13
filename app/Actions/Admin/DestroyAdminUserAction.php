<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Services\UserManager;
use Throwable;

/** POST /admin/usuarios/{id}/excluir. */
final class DestroyAdminUserAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
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
}
