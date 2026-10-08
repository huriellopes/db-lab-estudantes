<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;

/** A lixeira de usuários virou um filtro de Dados excluídos. GET /admin/usuarios/lixeira. */
final class RedirectTrashAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $this->redirect('/admin/excluidos?tipo=user');
    }
}
