<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Models\Entities\SchemaWithOwner;
use App\Models\SchemaRecord;
use App\Support\TableFilter;
use App\Support\TableQuery;

/** GET /admin/schemas. */
final class ShowAdminSchemasAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $pmaUrl = Config::get('PMA_URL');
        $allSchemas = SchemaRecord::allWithOwners();

        $query = TableQuery::fromParams($_GET, ['schema', 'owner', 'created']);
        $paginator = TableFilter::paginate(
            $allSchemas,
            $query,
            searchText: static fn (SchemaWithOwner $s): string => "{$s->dbName} {$s->ownerName} {$s->ownerEmail}",
            sortAccessors: [
                'schema' => static fn (SchemaWithOwner $s): string => mb_strtolower($s->dbName),
                'owner' => static fn (SchemaWithOwner $s): string => mb_strtolower($s->ownerName),
                'created' => static fn (SchemaWithOwner $s): int => $s->createdAt->getTimestamp(),
            ],
            perPage: 10,
        );

        $this->render('admin/schemas', [
            'pageTitle' => 'Todos os schemas',
            'paginator' => $paginator,
            'query' => $query,
            'totalSchemas' => count($allSchemas),
            'pmaUrl' => $pmaUrl !== null ? rtrim($pmaUrl, '/') : null,
        ]);
    }
}
