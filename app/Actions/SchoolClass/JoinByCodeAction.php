<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ClassException;
use App\Services\ClassManager;
use App\Services\RateLimiter;
use Throwable;

/**
 * "Entrar com código" do dashboard do aluno (código de turma ou de instituição).
 * POST /entrar-com-codigo. Até 10 tentativas a cada 15 min por conta, pra ninguém varrer códigos.
 */
final class JoinByCodeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $key = 'join-code:user:' . Auth::id();
        $limit = RateLimiter::check($key, maxAttempts: 10, windowSeconds: 900);
        if (!$limit->allowed) {
            $wait = (int) ceil($limit->retryAfterSeconds / 60);
            $this->respond(false, "Muitas tentativas. Aguarde {$wait} minuto(s) e tente de novo.", '/dashboard');
        }
        RateLimiter::hit($key);

        try {
            $joined = ClassManager::joinByCode((string) ($_POST['codigo'] ?? ''), Auth::user());
            $message = $joined['type'] === 'class'
                ? "Você entrou na turma {$joined['name']}."
                : "Você entrou na instituição {$joined['name']}. Agora seus professores já te encontram.";
            $this->respond(true, $message, '/dashboard');
        } catch (ClassException $e) {
            $this->respond(false, $e->getMessage(), '/dashboard');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('entrar com o código', $e), '/dashboard');
        }
    }
}
