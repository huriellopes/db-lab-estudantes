<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\Maintenance;

/** POST /admin/manutencao/opcache. */
final class ResetOpcacheAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        if (!Maintenance::resetOpcache()) {
            $this->respond(false, 'OPcache não está ativo neste servidor — nada para resetar.', '/admin/manutencao');
        }

        AuditLog::record('maintenance.opcache_reset');
        $this->respond(true, 'OPcache resetado: o PHP vai recompilar os arquivos na próxima requisição.', '/admin/manutencao');
    }
}
