<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User as UserModel;
use App\Services\UserManager;
use Throwable;

/** POST /admin/usuarios/{id}/restaurar. */
final class RestoreAdminUserAction extends Action
{
    public function __invoke(array $params = []): void
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
}
