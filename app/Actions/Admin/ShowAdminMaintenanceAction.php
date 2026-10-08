<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\BackupService;
use App\Services\HealthCheck;
use App\Services\Maintenance;
use App\Support\ErrorLogger;

/** Diagnóstico do ambiente + ações de manutenção (caches, espaço, modo manutenção). GET /admin/manutencao. */
final class ShowAdminMaintenanceAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $health = HealthCheck::all();

        $this->render('admin/maintenance', [
            'pageTitle' => 'Manutenção',
            'health' => $health,
            'checkedAt' => HealthCheck::checkedAt(),
            'cacheTtl' => HealthCheck::CACHE_TTL_SECONDS,
            'minFreeDiskPercent' => (int) (HealthCheck::MIN_FREE_DISK_RATIO * 100),
            'disk' => Maintenance::disk(),
            'storageUsage' => Maintenance::storageUsage(),
            'memory' => Maintenance::containerMemory(),
            'cpuLimit' => Maintenance::containerCpuLimit(),
            'cpuCount' => Maintenance::cpuCount(),
            'load' => Maintenance::loadAverage(),
            'phpVersion' => PHP_VERSION,
            'phpSettings' => Maintenance::phpSettings(),
            'phpExtensions' => Maintenance::phpExtensions(),
            'opcache' => Maintenance::opcache(),
            'mode' => Maintenance::mode(),
            'logFiles' => count(ErrorLogger::files()),
            'logRetentionDays' => ErrorLogger::RETENTION_DAYS,
            'backupCount' => count(BackupService::all()),
            'backupKeepLast' => BackupService::KEEP_LAST,
        ]);
    }
}
