<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;

/** GET /login. */
final class ShowLoginAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar',
            'old' => ['identifier' => ''],
            'errors' => [],
        ]);
    }
}
