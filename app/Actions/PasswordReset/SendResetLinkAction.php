<?php

declare(strict_types=1);

namespace App\Actions\PasswordReset;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Mailer;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\RateLimiter;
use App\Support\ClientIp;
use App\Support\RequestScheme;

/** POST /esqueci-senha. */
final class SendResetLinkAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $email = trim((string) ($_POST['email'] ?? ''));

        $ipKey = 'forgot:ip:' . ClientIp::resolve($_SERVER);
        $limit = RateLimiter::check($ipKey, maxAttempts: 5, windowSeconds: 900);

        if (!$limit->allowed) {
            $wait = (int) ceil($limit->retryAfterSeconds / 60);
            $this->render('auth/forgot_password', [
                'pageTitle' => 'Esqueci minha senha',
                'sent' => false,
                'errors' => ["Muitas tentativas. Aguarde {$wait} minuto(s) e tente de novo."],
            ]);

            return;
        }
        RateLimiter::hit($ipKey);

        // Roda o envio (se o e-mail existir e a conta estiver ativa) mas a resposta é
        // SEMPRE a mesma mensagem de "enviamos, se existir" — nunca revela se o e-mail
        // está cadastrado (evita enumeração de contas por aqui).
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = User::findByEmail($email);
            if ($user !== null && $user->active) {
                $token = PasswordResetToken::createFor($user->id);
                Mailer::send(
                    $user->email,
                    $user->name,
                    'Redefinir senha — DB Lab Estudantes',
                    $this->emailBody($user->name, $token),
                );
            }
        }

        $this->render('auth/forgot_password', ['pageTitle' => 'Esqueci minha senha', 'sent' => true, 'errors' => []]);
    }

    private function emailBody(string $name, string $plainToken): string
    {
        $scheme = RequestScheme::isHttps($_SERVER) ? 'https' : 'http';
        $host = Config::get('DB_PUBLIC_HOST', 'localhost');
        $link = "{$scheme}://{$host}/redefinir-senha/{$plainToken}";

        return <<<TEXT
            Oi, {$name}!

            Alguém (esperamos que você) pediu pra redefinir a senha da sua conta no DB Lab Estudantes.

            Clique no link abaixo pra escolher uma senha nova. Ele vale por 1 hora:
            {$link}

            Se não foi você que pediu, pode ignorar este e-mail — sua senha continua a mesma.
            TEXT;
    }
}
