<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;

/** POST /logout. */
final class LogoutAction extends Action
{
    public function __invoke(array $params = []): void
    {
        AuditLog::record('auth.logout');
        Auth::logout();
        $this->redirect('/login');
    }
}
