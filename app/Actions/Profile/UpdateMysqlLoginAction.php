<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User;
use App\Services\UserManager;
use App\Support\MysqlIdentifier;
use Throwable;

/**
 * Troca o login MySQL da pessoa (o mesmo usado para entrar no phpMyAdmin). Não renomeia
 * os databases já criados — só o identificador de login (ver aviso em
 * App\Services\UserManager::renameMysqlLogin). POST /profile/mysql-login.
 */
final class UpdateMysqlLoginAction extends Action
{
    public function __invoke(array $params = []): void
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
            $this->respond(false, $this->genericError('renomear o login', $e), '/profile');
        }
    }
}
