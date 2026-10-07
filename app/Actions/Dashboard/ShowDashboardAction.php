<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Models\Entities\SavedQuery as SavedQueryEntity;
use App\Models\Entities\Schema;
use App\Models\SavedQuery;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\HealthCheck;
use App\Support\TableFilter;
use App\Support\TableQuery;

/** Painel principal (aluno/professor): "Meus schemas" + console SQL. GET /dashboard. */
final class ShowDashboardAction extends Action
{
    public function __invoke(array $params = []): void
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
            // Status do lab (app/MySQL/phpMyAdmin) — só online/offline, ver partials/health-card.twig.
            'health' => HealthCheck::all(),
            // Prefixo dos schemas (users.schema_prefix) — pode ser diferente do login MySQL
            // atual se a pessoa renomeou o login depois de criar a conta.
            'schemaPrefix' => User::find(Auth::id())?->schemaPrefix ?? Auth::user()->mysqlLogin,
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
                static fn (SavedQueryEntity $q): array => $q->toSummaryArray(),
                SavedQuery::allForUser(Auth::id()),
            ),
        ]);
    }
}
