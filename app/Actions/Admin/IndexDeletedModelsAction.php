<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Models\AppSetting;
use App\Models\DeletedModel;
use App\Support\ArchiveGraph;
use App\Support\ArchiveSnapshot;
use App\Support\Paginator;
use App\Support\RetentionPolicy;
use DateTimeImmutable;

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

        $setting = AppSetting::find(AppSetting::ARCHIVE_RETENTION_DAYS);
        $env = Config::get('ARCHIVE_RETENTION_DAYS');
        $retention = RetentionPolicy::resolve($setting['value'] ?? null, $env);
        $now = new DateTimeImmutable();
        $found = DeletedModel::paginateBatches($model, $search, $days, $page);
        // Segredos e textos longos nunca chegam no template (Paginator é readonly: monta outro).
        $paginator = new Paginator(array_map(static function (array $batch) use ($retention, $now): array {
            $batch['days_left'] = RetentionPolicy::daysLeft(new DateTimeImmutable($batch['deleted_at']), $retention, $batch['keep'], $now);
            $batch['items'] = array_map(static fn (array $i): array => ['values' => ArchiveSnapshot::forDisplay($i['values'])] + $i, $batch['items']);

            return $batch;
        }, $found->items), $found->page, $found->perPage, $found->total);

        $this->render('admin/deleted', [
            'pageTitle' => 'Dados excluídos',
            'paginator' => $paginator,
            'models' => array_combine(ArchiveGraph::MODELS, array_map(ArchiveGraph::typeLabel(...), ArchiveGraph::MODELS)),
            'periods' => self::PERIODS,
            'filters' => ['tipo' => $model, 'q' => $search, 'dias' => $days],
            'retentionDays' => $retention,
            'retention' => [
                'source' => RetentionPolicy::source($setting['value'] ?? null, $env),
                'updatedBy' => $setting['updated_by'] ?? null,
                'updatedAt' => $setting['updated_at'] ?? null,
                // Sugestão do campo "dias" quando está desligado.
                'suggested' => $retention > 0 ? $retention : (RetentionPolicy::days($env) ?: 90),
            ],
        ]);
    }
}
