<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\User;
use App\Services\UserManager;
use App\Support\MysqlIdentifier;
use Throwable;

final class ProfileController extends Controller
{
    public function edit(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('profile/edit', [
            'pageTitle' => 'Meu perfil',
        ]);
    }

    /** Atualiza o nome de exibição. */
    public function update(array $params = []): void
    {
        Auth::requireLogin();

        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '' || mb_strlen($name) < 2) {
            $this->respond(false, 'Informe seu nome completo.', '/profile');
        }

        $userId = Auth::id();
        User::updateProfile($userId, $name);
        Auth::refresh(User::find($userId));

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

        if (!password_verify($currentPassword, $user->passwordHash)) {
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
            // Mantém o console SQL funcionando sem exigir novo login.
            Auth::refreshMysqlPassword($newPassword);
            $this->respond(true, 'Senha atualizada com sucesso.', '/profile');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível atualizar a senha: ' . $e->getMessage(), '/profile');
        }
    }

    /**
     * Troca o login MySQL da pessoa (o mesmo usado para entrar no phpMyAdmin). Não
     * renomeia os databases já criados — só o identificador de login (ver aviso em
     * App\Services\UserManager::renameMysqlLogin).
     */
    public function updateMysqlLogin(array $params = []): void
    {
        Auth::requireLogin();

        $newLogin = strtolower(trim((string) ($_POST['mysql_login'] ?? '')));
        $user = User::find(Auth::id());

        if (!MysqlIdentifier::isValidCustomLogin($newLogin)) {
            $this->respond(
                false,
                'Login inválido. Use 3 a 32 caracteres, começando com uma letra (minúsculas, números e "_").',
                '/profile',
            );
        }

        if ($newLogin === $user->mysqlLogin) {
            $this->respond(false, 'Esse já é o seu login atual.', '/profile');
        }

        if (User::mysqlLoginExists($newLogin)) {
            $this->respond(false, 'Esse login já está em uso por outra pessoa.', '/profile');
        }

        try {
            UserManager::renameMysqlLogin($user, $newLogin);
            Auth::refresh(User::find($user->id));
            $this->respond(true, "Login do phpMyAdmin atualizado para \"{$newLogin}\".", '/profile');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível renomear o login: ' . $e->getMessage(), '/profile');
        }
    }
}
