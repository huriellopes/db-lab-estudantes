<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\RateLimiter;
use App\Support\ClientIp;

/** POST /login. */
final class LoginAction extends Action
{
    /**
     * Hash bcrypt válido de uma senha que nunca existe de verdade — comparado quando o
     * identificador não bate com ninguém, só pra password_verify() gastar o mesmo tempo
     * de sempre. Sem isso, login com usuário inexistente responde bem mais rápido que
     * senha errada numa conta real (bcrypt não roda), e dá pra enumerar contas medindo
     * o tempo de resposta mesmo com a mensagem de erro sendo idêntica.
     */
    private const DUMMY_HASH = '$2y$10$SrEdFf1Lqpr7kzYYSn5Y4e5vzHy3bOF0.qwEJ7iIiyIv/Bkf1g21m';

    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];

        // Duas chaves: por IP (protege contra spray em várias contas) e por identificador
        // (protege uma conta específica de força bruta vinda de IPs diferentes).
        $ipKey = 'login:ip:' . ClientIp::resolve($_SERVER);
        $idKey = 'login:id:' . mb_strtolower($identifier);
        $ipLimit = RateLimiter::check($ipKey, maxAttempts: 15, windowSeconds: 300);
        $idLimit = RateLimiter::check($idKey, maxAttempts: 6, windowSeconds: 300);

        if (!$ipLimit->allowed || !$idLimit->allowed) {
            $wait = (int) ceil(max($ipLimit->retryAfterSeconds, $idLimit->retryAfterSeconds) / 60);
            $errors[] = "Muitas tentativas. Aguarde {$wait} minuto(s) e tente de novo.";
        } else {
            $user = User::findByEmailOrUsername($identifier);
            // password_verify() SEMPRE roda, mesmo sem usuário — ver DUMMY_HASH acima.
            $passwordValid = password_verify($password, $user?->passwordHash ?? self::DUMMY_HASH);

            if ($user === null || !$passwordValid) {
                RateLimiter::hit($ipKey);
                RateLimiter::hit($idKey);
                AuditLog::record('auth.login_failed', 'user', $user?->id, ['identificador' => $identifier]);
                $errors[] = 'E-mail/username ou senha inválidos.';
            } elseif (!$user->active) {
                RateLimiter::hit($ipKey);
                RateLimiter::hit($idKey);
                $errors[] = 'Esta conta está desativada. Fale com um professor ou admin.';
            } else {
                Auth::login($user, $password);
                User::touchLastLogin($user->id);

                if (($_POST['remember'] ?? null) === '1') {
                    Auth::remember($user->id);
                }

                AuditLog::record('auth.login', 'user', $user->id);
                $this->redirect('/dashboard');
            }
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar',
            'old' => ['identifier' => $identifier],
            'errors' => $errors,
        ]);
    }
}
