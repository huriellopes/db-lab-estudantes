<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\BackupService;
use InvalidArgumentException;
use Throwable;

/** Gera um dump .sql.gz agora. POST /admin/backups. */
final class StoreBackupAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $database = (string) ($_POST['database'] ?? '');

        try {
            $name = BackupService::create($database);
            AuditLog::record('backup.created', 'backup', $name, ['database' => $database]);
            $this->respond(true, "Backup \"{$name}\" gerado.", '/admin/backups');
        } catch (InvalidArgumentException $e) {
            $this->respond(false, $e->getMessage(), '/admin/backups');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('gerar o backup', $e), '/admin/backups');
        }
    }
}
