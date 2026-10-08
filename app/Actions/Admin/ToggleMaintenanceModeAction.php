<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\AuditLog;
use App\Services\Maintenance;

/** Liga/desliga o modo manutenção (503 pra quem não é admin). POST /admin/manutencao/modo. */
final class ToggleMaintenanceModeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        if (($_POST['enabled'] ?? '') !== '1') {
            Maintenance::disable();
            AuditLog::record('maintenance.mode_off');
            // O botão do banner (layouts/app.twig) aparece em qualquer página — volta pra ela.
            $this->respond(true, 'Modo manutenção desligado — o lab voltou a abrir para todos.', self::backPath($_SERVER['HTTP_REFERER'] ?? null));
        }

        $message = (string) ($_POST['message'] ?? '');
        if (!Maintenance::enable(Auth::user()->name, $message)) {
            $this->respond(false, 'Não foi possível ligar o modo manutenção (storage/cache sem escrita?).', '/admin/manutencao');
        }

        AuditLog::record('maintenance.mode_on', meta: ['message' => mb_substr(trim($message), 0, 300)]);
        $this->respond(true, 'Modo manutenção ligado — só admins acessam o lab até você desligar.', '/admin/manutencao');
    }

    /**
     * Só o CAMINHO do Referer (com a query string), nunca host/esquema — então o redirect é
     * sempre pra dentro da própria app, mesmo com um Referer forjado ("//evil.com" incluso).
     */
    public static function backPath(?string $referer): string
    {
        $parts = parse_url((string) $referer);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/admin/manutencao';
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
