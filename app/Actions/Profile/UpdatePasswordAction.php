<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User;
use App\Services\UserManager;
use App\Support\PasswordPolicy;
use Throwable;

/** Troca a senha, tanto na app quanto na conta MySQL real da pessoa. POST /profile/password. */
final class UpdatePasswordAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        $user = User::find(Auth::id());

        if (!password_verify($currentPassword, $user->passwordHash)) {
            $this->respond(false, 'Senha atual incorreta.', '/profile');
        }
        $passwordError = PasswordPolicy::validate($newPassword, $user->mysqlLogin, $user->email);
        if ($passwordError !== null) {
            $this->respond(false, $passwordError, '/profile');
        }
        if ($newPassword !== $newPasswordConfirm) {
            $this->respond(false, 'As senhas não conferem.', '/profile');
        }

        try {
            UserManager::resetPassword($user, $newPassword);
            // resetPassword derrubou todas as sessões e cookies de lembrar (inclusive os
            // daqui) — sincroniza esta sessão com o session_version novo pra ela continuar
            // valendo, e reemite o "manter conectado" deste navegador se ele tinha um.
            Auth::refresh(User::find($user->id));
            Auth::keepRememberedDevice($user->id);
            // Mantém o console SQL funcionando sem exigir novo login.
            Auth::refreshMysqlPassword($newPassword);
            $this->respond(true, 'Senha atualizada com sucesso.', '/profile');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('atualizar a senha', $e), '/profile');
        }
    }
}
