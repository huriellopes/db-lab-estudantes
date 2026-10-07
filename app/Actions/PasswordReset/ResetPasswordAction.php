<?php

declare(strict_types=1);

namespace App\Actions\PasswordReset;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Flash;
use App\Models\AuditLog;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\UserManager;
use App\Support\FlashType;
use App\Support\PasswordPolicy;
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
        $owner = User::find($record->userId);
        $passwordError = PasswordPolicy::validate($password, $owner?->mysqlLogin ?? '', $owner?->email ?? '');
        if ($passwordError !== null) {
            $errors[] = $passwordError;
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
        // Conta desativada depois do pedido do link: o "esqueci minha senha" não pode virar
        // um jeito de reativar acesso (SendResetLinkAction já não envia pra conta inativa).
        // consume() antes de trocar a senha: só uma requisição por link chega até aqui.
        if ($user === null || !$user->active || !PasswordResetToken::consume($record->id)) {
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => false,
                'errors' => ['Esse link expirou ou já foi usado. Peça um novo.'],
            ]);

            return;
        }

        try {
            UserManager::resetPassword($user, $password);

            AuditLog::record('auth.password_reset_by_link', 'user', $user->id, [], ['id' => $user->id, 'name' => $user->name]);
            Flash::set(FlashType::Success, 'Senha redefinida com sucesso! Faça login com a senha nova.');
            $this->redirect('/login');
        } catch (Throwable $e) {
            // O link já foi consumido acima — mostra como inválido pra pessoa pedir outro
            // (raro: só acontece se o banco/MySQL falhar no meio da troca).
            $this->render('auth/reset_password', [
                'pageTitle' => 'Redefinir senha',
                'token' => $token,
                'valid' => false,
                'errors' => [$this->genericError('redefinir a senha', $e) . ' Peça um novo link.'],
            ]);
        }
    }
}
