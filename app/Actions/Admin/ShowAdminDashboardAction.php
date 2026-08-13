<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Support\AdminStats;
use App\Support\Role;

/** Painel do super admin: controle total sobre usuários, papéis e schemas do lab inteiro. GET /admin. */
final class ShowAdminDashboardAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/index', [
            'pageTitle' => 'Administração',
            'stats' => new AdminStats(
                alunos: UserModel::countByRole(Role::Aluno),
                professores: UserModel::countByRole(Role::Professor),
                admins: UserModel::countByRole(Role::Admin),
                schemas: SchemaRecord::countAll(),
            ),
        ]);
    }
}
