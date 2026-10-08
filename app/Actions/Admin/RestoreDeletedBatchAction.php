<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** POST /admin/excluidos/{batch}/restaurar. */
final class RestoreDeletedBatchAction extends Action
{
    public const BATCH_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $batch = (string) ($params['batch'] ?? '');
        if (preg_match(self::BATCH_PATTERN, $batch) !== 1) {
            $this->respond(false, 'Lote inválido.', '/admin/excluidos');
        }

        try {
            Archiver::restore($batch);
            $this->respond(true, 'Restaurado. Se havia views, triggers ou rotinas, elas estão na biblioteca de consultas do dono como "Restaurar objetos de …".', '/admin/excluidos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/excluidos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('restaurar', $e), '/admin/excluidos');
        }
    }
}
