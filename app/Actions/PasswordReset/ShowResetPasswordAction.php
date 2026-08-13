<?php

declare(strict_types=1);

namespace App\Actions\PasswordReset;

use App\Core\Action;
use App\Core\Auth;
use App\Models\PasswordResetToken;

/** GET /redefinir-senha/{token}. */
final class ShowResetPasswordAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $token = (string) ($params['token'] ?? '');
        $valid = PasswordResetToken::findValid($token) !== null;

        $this->render('auth/reset_password', [
            'pageTitle' => 'Redefinir senha',
            'token' => $token,
            'valid' => $valid,
            'errors' => [],
        ]);
    }
}
