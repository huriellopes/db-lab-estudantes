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

        $this->render('dashboard/index', [
            'pageTitle' => 'Meu painel',
            'schemas' => SchemaRecord::allForUser(Auth::id()),
            'pmaUrl' => rtrim(Config::get('PMA_URL', 'http://localhost:8081'), '/'),
        ]);
    }
}
