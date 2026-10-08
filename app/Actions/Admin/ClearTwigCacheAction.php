<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\Maintenance;
use App\Support\ByteSize;

/** POST /admin/manutencao/cache-twig. */
final class ClearTwigCacheAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $removed = Maintenance::clearTwigCache();

        AuditLog::record('maintenance.twig_cache_cleared', meta: $removed);
        $this->respond(
            true,
            "Cache de templates limpo ({$removed['files']} arquivo(s), " . ByteSize::format($removed['bytes']) . ').',
            '/admin/manutencao',
        );
    }
}
