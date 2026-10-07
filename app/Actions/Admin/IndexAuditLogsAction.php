<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;

/** Trilha de auditoria global, com busca e filtros por ação/período. GET /admin/auditoria. */
final class IndexAuditLogsAction extends Action
{
    private const PERIODS = [1, 7, 30, 90, 365];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $search = trim((string) ($_GET['q'] ?? ''));
        $action = (string) ($_GET['acao'] ?? '');
        $days = in_array((int) ($_GET['dias'] ?? 30), self::PERIODS, true) ? (int) ($_GET['dias'] ?? 30) : 30;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->render('admin/audit', [
            'pageTitle' => 'Auditoria',
            'paginator' => AuditLog::paginate($search, $action, $days, $page),
            'actions' => AuditLog::distinctActions(),
            'periods' => self::PERIODS,
            'filters' => ['q' => $search, 'acao' => $action, 'dias' => $days],
        ]);
    }
}
