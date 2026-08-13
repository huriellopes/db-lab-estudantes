<?php

declare(strict_types=1);

namespace App\Actions\Connection;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Models\SchemaRecord;

/**
 * Página de auto-ajuda: como conectar num SGBD local (TablePlus, DBeaver, etc.), com ou
 * sem túnel SSH. GET /conectar.
 */
final class ShowConnectionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $pmaUrl = Config::get('PMA_URL');

        $this->render('connection/show', [
            'pageTitle' => 'Conectar via SGBD',
            'schemas' => SchemaRecord::allForUser(Auth::id()),
            'publicHost' => Config::get('DB_PUBLIC_HOST', 'localhost'),
            'externalPort' => Config::get('MYSQL_EXTERNAL_PORT', '3306'),
            'pmaUrl' => $pmaUrl !== null ? rtrim($pmaUrl, '/') : null,
        ]);
    }
}
