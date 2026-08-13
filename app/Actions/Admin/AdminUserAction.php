<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\User;
use App\Models\User as UserModel;
use App\Support\Role;

/**
 * Base comum das Actions do painel de admin que agem sobre um usuário-alvo pelo `id` da
 * rota (ver app/Actions/Admin/*). Herdar findManageableUserOrFail() daqui em vez de
 * duplicá-lo em cada Action evita esse check de segurança (bloquear ação sobre a própria
 * conta e sobre outras contas admin) divergir entre elas.
 */
abstract class AdminUserAction extends Action
{
    /** Bloqueia ações sobre a própria conta e sobre outras contas admin (não listadas neste painel). */
    protected function findManageableUserOrFail(int $id): User
    {
        $user = UserModel::find($id);

        if ($user === null || $user->role === Role::Admin) {
            $this->respond(false, 'Usuário não encontrado.', '/admin/usuarios');
        }
        if ($user->id === Auth::id()) {
            $this->respond(false, 'Você não pode gerenciar a própria conta por aqui.', '/admin/usuarios');
        }

        return $user;
    }
}
