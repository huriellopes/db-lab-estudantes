<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Flash;
use App\Models\User;
use App\Services\RateLimiter;
use App\Services\UserManager;
use App\Support\ClientIp;
use App\Support\FlashType;
use App\Support\RegistrationValidator;
use App\Support\Role;
use Throwable;

/** POST /register. */
final class RegisterAction extends Action
{
    public function __invoke(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $old = compact('name', 'email', 'username');

        $ipKey = 'register:ip:' . ClientIp::resolve($_SERVER);
        $ipLimit = RateLimiter::check($ipKey, maxAttempts: 8, windowSeconds: 900);

        if (!$ipLimit->allowed) {
            $wait = (int) ceil($ipLimit->retryAfterSeconds / 60);
            $errors = ["Muitas tentativas de cadastro por aqui. Aguarde {$wait} minuto(s) e tente de novo."];
        } else {
            RateLimiter::hit($ipKey);

            $errors = RegistrationValidator::validate(
                $name,
                $email,
                $username,
                $password,
                $passwordConfirm,
                User::emailExists($email),
                User::isLoginTaken($username),
            );

            if (!$errors) {
                try {
                    // O cadastro público é sempre "aluno" (ver Role::registrable()) — quem
                    // cria professores/admins é o próprio admin, pelo painel.
                    UserManager::provisionNewUser($name, $email, $username, $password, Role::registrable());

                    Flash::set(FlashType::Success, 'Cadastro realizado com sucesso! Faça login para continuar.');
                    $this->redirect('/login');
                } catch (Throwable $e) {
                    $errors[] = $this->genericError('concluir o cadastro', $e);
                }
            }
        }

        $this->render('auth/register', [
            'pageTitle' => 'Cadastro',
            'old' => $old,
            'errors' => $errors,
        ]);
    }
}
