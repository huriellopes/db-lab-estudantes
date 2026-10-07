<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Support\ErrorLogger;

/** Erros da aplicação (storage/logs), agrupados por recorrência + as entradas recentes. GET /admin/logs. */
final class ShowAdminLogsAction extends Action
{
    private const LEVELS = ['critical', 'error', 'warning', 'notice'];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $files = ErrorLogger::files();
        $date = (string) ($_GET['dia'] ?? '');
        if (!isset($files[$date])) {
            $date = (string) (array_key_first($files) ?? date('Y-m-d'));
        }
        $level = in_array($_GET['nivel'] ?? null, self::LEVELS, true) ? (string) $_GET['nivel'] : null;

        $entries = ErrorLogger::entriesFor($date, $level);

        $this->render('admin/logs', [
            'pageTitle' => 'Logs de erro',
            'days' => array_keys($files),
            'date' => $date,
            'level' => $level,
            'levels' => self::LEVELS,
            'groups' => ErrorLogger::group($entries),
            'entries' => array_slice($entries, 0, 100),
            'total' => count($entries),
        ]);
    }
}
