<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Mailer;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\RateLimiter;
use App\Services\UserManager;
use App\Support\ClientIp;
use App\Support\FlashType;
use App\Support\RequestScheme;
use Throwable;

/** Fluxo self-service de "esqueci minha senha", por e-mail (ver App\Core\Mailer). */
final class PasswordResetController extends Controller
{
    public function showForgot(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/forgot_password', ['pageTitle' => 'Esqueci minha senha', 'sent' => false, 'errors' => []]);
    }

    public function sendResetLink(array $params = []): void
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

    public function showReset(array $params): void
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

    public function resetPassword(array $params = []): void
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
