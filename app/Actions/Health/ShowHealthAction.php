<?php

declare(strict_types=1);

namespace App\Actions\Health;

use App\Core\Action;
use App\Core\Auth;
use App\Services\HealthCheck;
use App\Support\HealthStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Saúde do lab em JSON, pra monitor externo (UptimeRobot etc.): "ok"/"degraded" com 200,
 * "down" com 503 (ver HealthCheck::overall()). Anônimo recebe só o resumo — nome de serviço,
 * versão e latência ficam pro admin logado, igual ao card do /dashboard (ver HealthStatus).
 * Usa o cache de 30s do HealthCheck: requisição em massa não vira checagem em massa.
 * GET /health.
 */
final class ShowHealthAction extends Action
{
    public function __invoke(array $params = []): void
    {
        $statuses = HealthCheck::all();
        $status = HealthCheck::overall($statuses);

        $body = [
            'status' => $status,
            'checkedAt' => (HealthCheck::checkedAt() ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
        if (Auth::isAdmin()) {
            $body['checks'] = array_map(static fn (HealthStatus $s): array => $s->toArray(), $statuses);
        }

        http_response_code($status === 'down' ? 503 : 200);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($body);
    }
}
