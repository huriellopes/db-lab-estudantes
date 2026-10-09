<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Flash;
use App\Models\AuditLog;
use App\Support\FlashType;

/** Botão "Voltar para minha conta" do banner de impersonação. POST /impersonacao/sair. */
final class StopImpersonatingAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $impersonator = Auth::impersonator();
        if ($impersonator === null) {
            $this->redirect('/dashboard');
        }

        $target = Auth::user();
        AuditLog::record('impersonation.stop', 'user', $target?->id, ['como' => $target?->name, 'duracao_min' => intdiv(time() - (int) $impersonator['started_at'], 60)]);

        if (!Auth::stopImpersonating()) {
            $this->redirect('/login');
        }

        Flash::set(FlashType::Success, 'Você voltou para a sua conta.');
        $this->redirect('/admin/usuarios');
    }
}
