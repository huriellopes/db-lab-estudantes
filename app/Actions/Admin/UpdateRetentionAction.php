<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\DeletedModel;
use App\Support\RetentionPolicy;

/**
 * Liga/desliga o expurgo automático e define os dias. POST /admin/excluidos/expurgo —
 * ativo=1|0, dias=N. O valor salvo passa a valer no lugar de ARCHIVE_RETENTION_DAYS do .env
 * (ver RetentionPolicy::current); o loop diário (docker/archive-purge-loop.sh) lê na próxima rodada.
 */
final class UpdateRetentionAction extends Action
{
    public const MAX_DAYS = 3650;

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $enabled = ($_POST['ativo'] ?? '') === '1';
        $days = 0;
        if ($enabled) {
            $raw = trim((string) ($_POST['dias'] ?? ''));
            $days = ctype_digit($raw) ? (int) $raw : 0;
            if ($days < 1 || $days > self::MAX_DAYS) {
                $this->respond(false, 'Informe de 1 a ' . self::MAX_DAYS . ' dias.', '/admin/excluidos');
            }
        }

        $before = RetentionPolicy::current();
        AppSetting::set(AppSetting::ARCHIVE_RETENTION_DAYS, (string) $days, Auth::user()->name);
        AuditLog::record('archive.retention_changed', meta: ['de' => $before, 'para' => $days]);

        if ($days === 0) {
            $this->respond(true, 'Expurgo automático desligado — nada sai de Dados excluídos sozinho.', '/admin/excluidos');
        }

        $due = count(DeletedModel::expiredBatches($days));
        $this->respond(
            true,
            "Expurgo automático ligado: lotes com mais de {$days} dias saem uma vez por dia."
            . ($due > 0 ? " {$due} lote(s) já passaram do prazo e saem na próxima rodada." : ''),
            '/admin/excluidos',
        );
    }
}
