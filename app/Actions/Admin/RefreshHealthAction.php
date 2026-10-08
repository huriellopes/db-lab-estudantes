<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\HealthCheck;

/** Descarta o cache de 30s do status do lab e checa tudo de novo. POST /admin/manutencao/status. */
final class RefreshHealthAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        HealthCheck::forget();
        $offline = array_filter(HealthCheck::all(), static fn ($s): bool => !$s->ok);

        AuditLog::record('maintenance.health_refreshed', meta: ['offline' => count($offline)]);
        $this->respond(
            true,
            $offline === [] ? 'Status verificado agora: tudo operando.' : 'Status verificado agora: ' . count($offline) . ' serviço(s) com problema.',
            '/admin/manutencao',
        );
    }
}
