<?php

declare(strict_types=1);

namespace App\Actions\Health;

use App\Core\Action;

/**
 * Liveness: só diz que nginx + PHP-FPM estão respondendo, sem tocar em banco nem disco. É o
 * que o HEALTHCHECK do Docker usa (ver Dockerfile) — o autoheal reinicia o container quando
 * ele fica "unhealthy", e reiniciar a app não resolve MySQL fora ou disco cheio (só criaria
 * um loop de restart). Pra saúde de verdade, ver ShowHealthAction. GET /health/live.
 */
final class LiveHealthAction extends Action
{
    public function __invoke(array $params = []): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['status' => 'ok']);
    }
}
