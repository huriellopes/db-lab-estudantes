<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Models\SchemaRecord;

/** Página de auto-ajuda: como conectar num SGBD local (TablePlus, DBeaver, etc.), com ou sem túnel SSH. */
final class ConnectionController extends Controller
{
    public function show(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('connection/show', [
            'pageTitle' => 'Conectar via SGBD',
            'schemas' => SchemaRecord::allForUser(Auth::id()),
            'publicHost' => Config::get('DB_PUBLIC_HOST', 'localhost'),
            'externalPort' => Config::get('MYSQL_EXTERNAL_PORT', '3306'),
        ]);
    }
}
