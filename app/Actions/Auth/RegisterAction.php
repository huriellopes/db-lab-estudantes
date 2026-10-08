<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Flash;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\User;
use App\Services\RateLimiter;
use App\Services\UserManager;
use App\Support\ClientIp;
use App\Support\FlashType;
use App\Support\InviteCode;
use App\Support\RateLimits;
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
        $inviteInput = trim((string) ($_POST['codigo_instituicao'] ?? ''));
        $old = compact('name', 'email', 'username') + ['codigo_instituicao' => $inviteInput];

        // Com código de instituição válido o cadastro conta no limite DO CÓDIGO (generoso, cabe a
        // turma toda), não no do IP — a sala inteira sai pelo mesmo IP. Ver App\Support\RateLimits.
        $inviteCode = $inviteInput !== '' ? InviteCode::normalize($inviteInput) : null;
        $validCode = $inviteCode !== null && Institution::findByInviteCode($inviteCode) !== null ? $inviteCode : null;
        [$limitKey, $limitMax, $window] = RateLimits::register(ClientIp::resolve($_SERVER), $validCode);
        $ipLimit = RateLimiter::check($limitKey, maxAttempts: $limitMax, windowSeconds: $window);

        if (!$ipLimit->allowed) {
            $wait = (int) ceil($ipLimit->retryAfterSeconds / 60);
            $errors = ["Muitas tentativas de cadastro por aqui. Aguarde {$wait} minuto(s) e tente de novo."];
        } else {
            RateLimiter::hit($limitKey);

            $errors = RegistrationValidator::validate(
                $name,
                $email,
                $username,
                $password,
                $passwordConfirm,
                User::emailExists($email),
                User::isLoginTaken($username),
            );

            $institution = null;
            if (!$errors && $inviteInput !== '') {
                $code = InviteCode::normalize($inviteInput);
                $institution = $code !== null ? Institution::findByInviteCode($code) : null;
                if ($institution === null) {
                    $errors[] = 'Código da instituição inválido. Confira com seu professor ou deixe o campo em branco.';
                }
            }

            if (!$errors) {
                try {
                    // O cadastro público é sempre "aluno" (ver Role::registrable()) — quem
                    // cria professores/admins é o próprio admin, pelo painel.
                    $user = UserManager::provisionNewUser($name, $email, $username, $password, Role::registrable());
                    if ($institution !== null) {
                        InstitutionMember::add($institution->id, $user->id, Role::Aluno->value);
                    }

                    AuditLog::record('auth.registered', 'user', $username, ['email' => $email, 'instituicao' => $institution?->name], ['id' => null, 'name' => $name]);
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
