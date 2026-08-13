<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\Entities\SavedQuery as SavedQueryEntity;
use App\Models\Entities\Schema;
use App\Models\SavedQuery;
use App\Models\SchemaRecord;
use App\Support\TableFilter;
use App\Support\TableQuery;

final class DashboardController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $pmaUrl = Config::get('PMA_URL');
        $allSchemas = SchemaRecord::allForUser(Auth::id());

        // Sem sort explícito, mantém a ordem que já vem do banco (created_at DESC — mais recente primeiro).
        $query = TableQuery::fromParams($_GET, ['schema', 'created']);
        $paginator = TableFilter::paginate(
            $allSchemas,
            $query,
            searchText: static fn (Schema $s): string => $s->dbName,
            sortAccessors: [
                'schema' => static fn (Schema $s): string => mb_strtolower($s->dbName),
                'created' => static fn (Schema $s): int => $s->createdAt->getTimestamp(),
            ],
            perPage: 8,
        );

        $this->render('dashboard/index', [
            'pageTitle' => 'Meu painel',
            'paginator' => $paginator,
            'query' => $query,
            // Lista completa (sem paginação) só dos nomes, pra alimentar o seletor de
            // schema do console SQL — não faz sentido paginar um <select>.
            'schemaNames' => array_map(static fn (Schema $s): string => $s->dbName, $allSchemas),
            // Nulo quando o phpMyAdmin não está exposto publicamente (ex.: produção,
            // onde ele só é acessível via túnel SSH) — a view esconde o link nesse caso.
            'pmaUrl' => $pmaUrl !== null ? rtrim($pmaUrl, '/') : null,
            // Biblioteca de comandos salvos pra alimentar o console SQL (ver Alpine
            // `sqlConsole` em resources/js/app.js) — já serializada no formato que o JS espera.
            'savedQueries' => array_map(
                static fn (SavedQueryEntity $q): array => [
                    'id' => $q->id,
                    'title' => $q->title,
                    'schema' => $q->schemaName,
                    'sql' => $q->sqlText,
                ],
                SavedQuery::allForUser(Auth::id()),
            ),
        ]);
    }
}
