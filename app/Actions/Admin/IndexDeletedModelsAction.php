<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\DeletedModel;
use App\Support\ArchiveGraph;
use App\Support\ArchiveSnapshot;
use App\Support\Paginator;

/** Dados excluídos: lotes do arquivo para restaurar ou excluir definitivamente. GET /admin/excluidos. */
final class IndexDeletedModelsAction extends Action
{
    private const PERIODS = [0, 7, 30, 90, 365];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $model = in_array($_GET['tipo'] ?? '', ArchiveGraph::MODELS, true) ? (string) $_GET['tipo'] : '';
        $search = trim((string) ($_GET['q'] ?? ''));
        $days = in_array((int) ($_GET['dias'] ?? 0), self::PERIODS, true) ? (int) ($_GET['dias'] ?? 0) : 0;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $found = DeletedModel::paginateBatches($model, $search, $days, $page);
        // Segredos e textos longos nunca chegam no template (Paginator é readonly: monta outro).
        $paginator = new Paginator(array_map(static function (array $batch): array {
            $batch['items'] = array_map(static fn (array $i): array => ['values' => ArchiveSnapshot::forDisplay($i['values'])] + $i, $batch['items']);

            return $batch;
        }, $found->items), $found->page, $found->perPage, $found->total);

        $this->render('admin/deleted', [
            'pageTitle' => 'Dados excluídos',
            'paginator' => $paginator,
            'models' => array_combine(ArchiveGraph::MODELS, array_map(ArchiveGraph::typeLabel(...), ArchiveGraph::MODELS)),
            'periods' => self::PERIODS,
            'filters' => ['tipo' => $model, 'q' => $search, 'dias' => $days],
        ]);
    }
}
