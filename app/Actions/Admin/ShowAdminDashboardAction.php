<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AdminMetrics;
use App\Services\HealthCheck;

/** Painel do super admin: métricas do lab, saúde dos serviços e atalhos de gestão. GET /admin. */
final class ShowAdminDashboardAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/index', [
            'pageTitle' => 'Administração',
            'stats' => AdminMetrics::collect(),
            'health' => HealthCheck::all(),
        ]);
    }
}
