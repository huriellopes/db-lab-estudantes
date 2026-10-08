<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\LogTail;

/** `tail -f` dos logs no navegador (aplicação, MySQL, auditoria). GET /admin/logs/ao-vivo. */
final class ShowLiveLogsAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $source = in_array($_GET['fonte'] ?? null, LogTail::SOURCES, true) ? (string) $_GET['fonte'] : 'app';

        $this->render('admin/logs_live', [
            'pageTitle' => 'Logs ao vivo',
            'source' => $source,
        ]);
    }
}
