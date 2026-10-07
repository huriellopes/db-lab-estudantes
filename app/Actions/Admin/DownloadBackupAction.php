<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\BackupService;

/** GET /admin/backups/{name}. O nome passa pela whitelist de BackupService::path() — sem path traversal. */
final class DownloadBackupAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $name = (string) ($params['name'] ?? '');
        $path = BackupService::path($name);
        if ($path === null) {
            $this->respond(false, 'Backup não encontrado.', '/admin/backups');
        }

        AuditLog::record('backup.downloaded', 'backup', $name);

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
