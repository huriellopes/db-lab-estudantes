<?php

declare(strict_types=1);

namespace App\Actions\PasswordReset;

use App\Core\Action;
use App\Core\Auth;

/** GET /esqueci-senha. */
final class ShowForgotPasswordAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/forgot_password', ['pageTitle' => 'Esqueci minha senha', 'sent' => false, 'errors' => []]);
    }
}
