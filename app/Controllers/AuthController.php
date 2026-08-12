<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\User;
use App\Services\SchemaProvisioner;
use App\Support\FlashType;
use App\Support\MysqlIdentifier;
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
            'old' => ['email' => ''],
            'errors' => [],
        ]);
    }

    public function login(array $params = []): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];

        $user = User::findByEmail($email);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            $errors[] = 'E-mail ou senha inválidos.';
        } else {
            Auth::login($user);
            $this->redirect('/dashboard');
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar',
            'old' => ['email' => $email],
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
            'old' => ['name' => '', 'email' => '', 'role' => Role::Aluno->value],
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
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $roleInput = (string) ($_POST['role'] ?? '');
        $old = compact('name', 'email') + ['role' => $roleInput];

        $errors = RegistrationValidator::validate(
            $name,
            $email,
            $password,
            $passwordConfirm,
            $roleInput,
            User::emailExists($email),
        );

        if (!$errors) {
            // Sem transação PDO aqui: CREATE USER é DDL (ver aviso em SchemaProvisioner).
            // Em caso de falha no meio do caminho, desfazemos manualmente o que já foi criado.
            $userId = null;
            $mysqlLogin = null;

            try {
                $role = Role::from($roleInput);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userId = User::create($name, $email, $passwordHash, $role);

                $mysqlLogin = MysqlIdentifier::build($userId, $email);
                User::setMysqlLogin($userId, $mysqlLogin);

                // Cria a conta MySQL real do aluno/professor, com a MESMA senha da conta
                // na plataforma — ele usa esse login para acessar o phpMyAdmin depois.
                SchemaProvisioner::createMysqlAccount($mysqlLogin, $password);

                Flash::set(FlashType::Success, 'Cadastro realizado com sucesso! Faça login para continuar.');
                $this->redirect('/login');
            } catch (Throwable $e) {
                if ($userId !== null) {
                    try {
                        User::delete($userId);
                    } catch (Throwable $cleanupError) {
                        // ignora falha de limpeza, o erro principal já será reportado abaixo
                    }
                }
                if ($mysqlLogin !== null) {
                    try {
                        SchemaProvisioner::dropMysqlAccount($mysqlLogin);
                    } catch (Throwable $cleanupError) {
                        // ignora falha de limpeza, o erro principal já será reportado abaixo
                    }
                }
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
