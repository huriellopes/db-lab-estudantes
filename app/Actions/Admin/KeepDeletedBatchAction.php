<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\DeletedModel;

/** Marca/desmarca "não apagar" (fora do expurgo automático). POST /admin/excluidos/{batch}/manter — manter=1|0. */
final class KeepDeletedBatchAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $batch = (string) ($params['batch'] ?? '');
        $items = preg_match(RestoreDeletedBatchAction::BATCH_PATTERN, $batch) === 1 ? DeletedModel::itemsOfBatch($batch) : [];
        if ($items === []) {
            $this->respond(false, 'Lote não encontrado.', '/admin/excluidos');
        }

        $keep = ($_POST['manter'] ?? '') === '1';
        DeletedModel::setKeep($batch, $keep);
        AuditLog::record($keep ? 'archive.keep_set' : 'archive.keep_cleared', $items[0]['model'], $items[0]['model_id'], ['batch' => $batch, 'rotulo' => $items[0]['label']]);

        $this->respond(true, $keep ? "\"{$items[0]['label']}\" não será apagado pelo expurgo automático." : "\"{$items[0]['label']}\" volta a seguir o expurgo automático.", '/admin/excluidos');
    }
}
