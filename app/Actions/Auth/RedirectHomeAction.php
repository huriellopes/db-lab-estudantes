<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;

/** GET / — manda pro dashboard (logado) ou pro login (não logado). */
final class RedirectHomeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }
}
