<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\SchemaRecord;

final class DashboardController extends Controller
{
    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $pmaUrl = Config::get('PMA_URL');

        $this->render('dashboard/index', [
            'pageTitle' => 'Meu painel',
            'schemas' => SchemaRecord::allForUser(Auth::id()),
            // Nulo quando o phpMyAdmin não está exposto publicamente (ex.: produção,
            // onde ele só é acessível via túnel SSH) — a view esconde o link nesse caso.
            'pmaUrl' => $pmaUrl !== null ? rtrim($pmaUrl, '/') : null,
        ]);
    }
}
