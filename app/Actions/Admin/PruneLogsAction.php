<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\Maintenance;
use App\Support\ByteSize;

/** Apaga logs de erro com mais de N dias (o de hoje nunca). POST /admin/manutencao/logs. */
final class PruneLogsAction extends Action
{
    public const DAY_OPTIONS = [1, 3, 7];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $days = (int) ($_POST['days'] ?? 0);
        if (!in_array($days, self::DAY_OPTIONS, true)) {
            $this->respond(false, 'Período inválido.', '/admin/manutencao');
        }

        $removed = Maintenance::pruneLogs($days);

        AuditLog::record('maintenance.logs_pruned', meta: ['days' => $days, ...$removed]);
        $this->respond(
            true,
            $removed['files'] === 0
                ? "Nenhum log com mais de {$days} dia(s) para apagar."
                : "{$removed['files']} arquivo(s) de log apagado(s), " . ByteSize::format($removed['bytes']) . ' liberados.',
            '/admin/manutencao',
        );
    }
}
