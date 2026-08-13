<?php

declare(strict_types=1);

namespace App\Actions\PasswordReset;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Flash;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\UserManager;
use App\Support\FlashType;
use Throwable;

/** POST /redefinir-senha. */
final class ResetPasswordAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $record = PasswordResetToken::findValid($token);
        if ($record === null) {
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => false,
                'errors' => ['Esse link expirou ou já foi usado. Peça um novo.'],
            ]);

            return;
        }

        $errors = [];
        if (strlen($password) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'As senhas não conferem.';
        }

        if ($errors !== []) {
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => true,
                'errors' => $errors,
            ]);

            return;
        }

        $user = User::find($record->userId);
        if ($user === null) {
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => false,
                'errors' => ['Conta não encontrada.'],
            ]);

            return;
        }

        try {
            UserManager::resetPassword($user, $password);
            PasswordResetToken::markUsed($record->id);

            Flash::set(FlashType::Success, 'Senha redefinida com sucesso! Faça login com a senha nova.');
            $this->redirect('/login');
        } catch (Throwable $e) {
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => true,
                'errors' => [$this->genericError('redefinir a senha', $e)],
            ]);
        }
    }
}
