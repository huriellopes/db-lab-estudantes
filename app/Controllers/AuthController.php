<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\User;
use App\Services\UserManager;
use App\Support\FlashType;
use App\Support\RegistrationValidator;
use App\Support\Role;
use Throwable;

final class AuthController extends Controller
{
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

        $user = User::findByEmailOrUsername($identifier);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            $errors[] = 'E-mail/username ou senha inválidos.';
        } elseif (!$user->active) {
            $errors[] = 'Esta conta está desativada. Fale com um professor ou admin.';
        } else {
            Auth::login($user);
            $this->redirect('/dashboard');
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
