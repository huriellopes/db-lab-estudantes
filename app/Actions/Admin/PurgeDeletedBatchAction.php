<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\DeletedModel;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** Exclusão definitiva — pede o rótulo digitado como confirmação. POST /admin/excluidos/{batch}/excluir. */
final class PurgeDeletedBatchAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $batch = (string) ($params['batch'] ?? '');
        $items = preg_match(RestoreDeletedBatchAction::BATCH_PATTERN, $batch) === 1 ? DeletedModel::itemsOfBatch($batch) : [];
        if ($items === []) {
            $this->respond(false, 'Lote não encontrado.', '/admin/excluidos');
        }
        if (trim((string) ($_POST['confirmacao'] ?? '')) !== $items[0]['label']) {
            $this->respond(false, 'Para excluir definitivamente, digite exatamente: ' . $items[0]['label'], '/admin/excluidos');
        }

        try {
            Archiver::purge($batch);
            $this->respond(true, "\"{$items[0]['label']}\" excluído definitivamente.", '/admin/excluidos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/excluidos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir definitivamente', $e), '/admin/excluidos');
        }
    }
}
