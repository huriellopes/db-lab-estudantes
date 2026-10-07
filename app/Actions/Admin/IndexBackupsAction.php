<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\BackupService;

/** GET /admin/backups. */
final class IndexBackupsAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/backups', [
            'pageTitle' => 'Backups',
            'backups' => BackupService::all(),
            'databases' => BackupService::availableDatabases(),
            'keepLast' => BackupService::KEEP_LAST,
        ]);
    }
}
