<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\Maintenance;
use App\Support\ByteSize;

/** Mantém só os N backups mais recentes. POST /admin/manutencao/backups. */
final class PruneBackupsAction extends Action
{
    public const KEEP_OPTIONS = [1, 3, 5];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $keep = (int) ($_POST['keep'] ?? -1);
        if (!in_array($keep, self::KEEP_OPTIONS, true)) {
            $this->respond(false, 'Quantidade inválida.', '/admin/manutencao');
        }

        $removed = Maintenance::pruneBackups($keep);

        AuditLog::record('maintenance.backups_pruned', meta: ['keep' => $keep, ...$removed]);
        $this->respond(
            true,
            $removed['files'] === 0
                ? "Já havia no máximo {$keep} backup(s) — nada apagado."
                : "{$removed['files']} backup(s) antigo(s) apagado(s), " . ByteSize::format($removed['bytes']) . ' liberados.',
            '/admin/manutencao',
        );
    }
}
