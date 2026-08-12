<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\User;
use App\Services\UserManager;
use Throwable;

class ProfileController extends Controller
{
    public function edit(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('profile/edit', [
            'pageTitle' => 'Meu perfil',
            'errors' => [],
        ]);
    }

    /** Atualiza o nome de exibição. */
    public function update(array $params = []): void
    {
        Auth::requireLogin();

        $name = trim($_POST['name'] ?? '');

        if ($name === '' || mb_strlen($name) < 2) {
            $this->respond(false, 'Informe seu nome completo.', '/profile');
        }

        User::updateProfile(Auth::id(), $name);
        Auth::refresh(User::find(Auth::id()));

        $this->respond(true, 'Nome atualizado com sucesso.', '/profile');
    }

    /** Troca a senha, tanto na app quanto na conta MySQL real da pessoa. */
    public function updatePassword(array $params = []): void
    {
        Auth::requireLogin();

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        $user = User::find(Auth::id());

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->respond(false, 'Senha atual incorreta.', '/profile');
        }
        if (strlen($newPassword) < 6) {
            $this->respond(false, 'A nova senha deve ter pelo menos 6 caracteres.', '/profile');
        }
        if ($newPassword !== $newPasswordConfirm) {
            $this->respond(false, 'As senhas não conferem.', '/profile');
        }

        try {
            UserManager::resetPassword($user, $newPassword);
            $this->respond(true, 'Senha atualizada com sucesso.', '/profile');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível atualizar a senha: ' . $e->getMessage(), '/profile');
        }
    }
}
