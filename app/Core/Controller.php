<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\FlashType;
use Throwable;

abstract class Controller
{
    /** O layout é declarado dentro do próprio template via {% extends %}. */
    protected function render(string $template, array $data = []): void
    {
        echo View::render($template, $data);
    }

    protected function redirect(string $path): never
    {
        header("Location: {$path}");
        exit;
    }

    protected function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Responde de forma unificada às duas formas de submissão que as views usam:
     * um <form> comum (sem JS, ou Alpine ainda não carregou) recebe o redirect+flash
     * de sempre; uma chamada via Axios (Alpine, com o header X-Requested-With) recebe
     * JSON, para atualizar a página sem recarregar.
     */
    protected function respond(bool $success, string $message, string $redirectTo): never
    {
        if ($this->isAjax()) {
            http_response_code($success ? 200 : 422);
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }

        Flash::set($success ? FlashType::Success : FlashType::Error, $message);
        $this->redirect($redirectTo);
    }

    /**
     * Loga o erro real (detalhe de PDO/MySQL nunca deve chegar no navegador — pode
     * revelar estrutura do banco) e devolve uma mensagem genérica no padrão já usado em
     * toda a app ("Não foi possível {$action}."). Uso: `$this->respond(false,
     * $this->genericError('criar o schema', $e), '/dashboard');`
     *
     * Exceção deliberada: App\Controllers\SqlConsoleController mostra o erro real do
     * MySQL de propósito (é um console SQL — o erro é o produto, ver SECURITY.md).
     */
    protected function genericError(string $action, Throwable $e): string
    {
        error_log(static::class . " — não foi possível {$action}: {$e->getMessage()}");

        return "Não foi possível {$action}. Tente de novo em instantes.";
    }
}
