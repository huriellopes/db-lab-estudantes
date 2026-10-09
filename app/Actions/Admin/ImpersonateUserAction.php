<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Core\Flash;
use App\Models\AuditLog;
use App\Support\FlashType;
use App\Support\Policy;

/**
 * "Entrar como" um professor ou aluno. POST /admin/usuarios/{id}/impersonar. O banner do layout
 * mostra com quem se está logado e o botão de voltar (POST /impersonacao/sair); toda ação feita
 * no meio vai pra auditoria como "admin X como Y" (ver AuditLog::record).
 */
final class ImpersonateUserAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);
        if (!Policy::canImpersonate(Auth::user(), Auth::isImpersonating(), $target)) {
            $this->respond(false, 'Só dá para entrar como professor ou aluno com a conta ativa.', '/admin/usuarios');
        }

        // Antes da troca: aqui quem age ainda é o admin.
        AuditLog::record('impersonation.start', 'user', $target->id, ['como' => $target->name, 'papel' => $target->role->value, 'email' => $target->email]);
        Auth::impersonate($target);

        Flash::set(FlashType::Success, "Você entrou como {$target->name}. Tudo o que fizer fica na auditoria em seu nome.");
        $this->redirect('/dashboard');
    }
}
