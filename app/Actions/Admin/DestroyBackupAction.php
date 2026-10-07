<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\BackupService;

/** POST /admin/backups/{name}/excluir. */
final class DestroyBackupAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $name = (string) ($params['name'] ?? '');
        if (!BackupService::delete($name)) {
            $this->respond(false, 'Backup não encontrado.', '/admin/backups');
        }

        AuditLog::record('backup.deleted', 'backup', $name);
        $this->respond(true, "Backup \"{$name}\" excluído.", '/admin/backups');
    }
}
