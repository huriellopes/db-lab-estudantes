<?php

declare(strict_types=1);

namespace App\Actions\SqlConsole;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User;
use App\Services\RateLimiter;

/**
 * Recacheia a senha MySQL na sessão sem exigir logout/login (ver comentário em
 * App\Actions\SqlConsole\RunSqlAction) — a pessoa confirma a própria senha da conta aqui
 * mesmo, e Auth::refreshMysqlPassword() guarda ela criptografada, do mesmo jeito que um
 * login normal guardaria. POST /dashboard/sql/confirmar-senha.
 */
final class ConfirmMysqlPasswordAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();
        if (Auth::isImpersonating()) {
            $this->json(false, Auth::IMPERSONATION_NO_MYSQL);
        }

        $password = (string) ($_POST['password'] ?? '');

        // Por usuário (não por IP): quem já tem sessão válida aqui já passou pelo rate
        // limit do login — isso é só uma segunda trava pra não virar um oráculo de senha
        // caso a sessão em si tenha vazado.
        $key = 'sql-console-confirm-password:user:' . Auth::id();
        $limit = RateLimiter::check($key, maxAttempts: 8, windowSeconds: 300);
        if (!$limit->allowed) {
            $wait = (int) ceil($limit->retryAfterSeconds / 60);
            $this->json(false, "Muitas tentativas. Aguarde {$wait} minuto(s) e tente de novo.");
        }

        $userRecord = User::find(Auth::id());
        if ($userRecord === null || !password_verify($password, $userRecord->passwordHash)) {
            RateLimiter::hit($key);
            $this->json(false, 'Senha incorreta.');
        }

        Auth::refreshMysqlPassword($password);
        $this->json(true, 'Senha confirmada — pode continuar usando o console.');
    }
}
