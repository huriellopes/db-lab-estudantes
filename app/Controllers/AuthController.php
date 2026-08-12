<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\User;
use App\Services\RateLimiter;
use App\Services\UserManager;
use App\Support\ClientIp;
use App\Support\FlashType;
use App\Support\RegistrationValidator;
use App\Support\Role;
use Throwable;

final class AuthController extends Controller
{
    /**
     * Hash bcrypt válido de uma senha que nunca existe de verdade — comparado quando o
     * identificador não bate com ninguém, só pra password_verify() gastar o mesmo tempo
     * de sempre. Sem isso, login com usuário inexistente responde bem mais rápido que
     * senha errada numa conta real (bcrypt não roda), e dá pra enumerar contas medindo
     * o tempo de resposta mesmo com a mensagem de erro sendo idêntica.
     */
    private const DUMMY_HASH = '$2y$10$SrEdFf1Lqpr7kzYYSn5Y4e5vzHy3bOF0.qwEJ7iIiyIv/Bkf1g21m';

    public function redirectHome(array $params = []): void
    {
        $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }

    public function showLogin(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar',
            'old' => ['identifier' => ''],
            'errors' => [],
        ]);
    }

    public function login(array $params = []): void
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
                $errors[] = 'E-mail/username ou senha inválidos.';
            } elseif (!$user->active) {
                RateLimiter::hit($ipKey);
                RateLimiter::hit($idKey);
                $errors[] = 'Esta conta está desativada. Fale com um professor ou admin.';
            } else {
                Auth::login($user, $password);
                $this->redirect('/dashboard');
            }
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar',
            'old' => ['identifier' => $identifier],
            'errors' => $errors,
        ]);
    }

    public function showRegister(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/register', [
            'pageTitle' => 'Cadastro',
            'old' => ['name' => '', 'email' => '', 'username' => ''],
            'errors' => [],
        ]);
    }

    public function register(array $params = []): void
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
                User::mysqlLoginExists($username),
            );

            if (!$errors) {
                try {
                    // O cadastro público é sempre "aluno" (ver Role::registrable()) — quem
                    // cria professores/admins é o próprio admin, pelo painel.
                    UserManager::provisionNewUser($name, $email, $username, $password, Role::registrable());

                    Flash::set(FlashType::Success, 'Cadastro realizado com sucesso! Faça login para continuar.');
                    $this->redirect('/login');
                } catch (Throwable $e) {
                    $errors[] = 'Não foi possível concluir o cadastro: ' . $e->getMessage();
                }
            }
        }

        $this->render('auth/register', [
            'pageTitle' => 'Cadastro',
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    public function logout(array $params = []): void
    {
        Auth::logout();
        $this->redirect('/login');
    }
}
