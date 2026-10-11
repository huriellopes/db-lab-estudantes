<?php

declare(strict_types=1);

namespace App\Actions\Connection;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Models\SchemaRecord;
use App\Support\SshTunnel;

/**
 * Página de auto-ajuda: como conectar num SGBD local (TablePlus, DBeaver, etc.). Em produção
 * é sempre via túnel SSH (a porta do MySQL não é pública — ver SshTunnel); sem túnel
 * configurado (dev local), mostra a conexão direta. GET /conectar.
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
            'tunnel' => SshTunnel::fromEnv([
                'SSH_TUNNEL_HOST' => Config::get('SSH_TUNNEL_HOST'),
                'SSH_TUNNEL_PORT' => Config::get('SSH_TUNNEL_PORT'),
                'SSH_TUNNEL_USER' => Config::get('SSH_TUNNEL_USER'),
                'SSH_TUNNEL_PASSWORD' => Config::get('SSH_TUNNEL_PASSWORD'),
                'MYSQL_PORT' => Config::get('MYSQL_PORT'),
            ]),
        ]);
    }
}
