<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/codigo — acao=gerar|desativar. */
final class InstitutionCodeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            if (($_POST['acao'] ?? '') === 'desativar') {
                InstitutionManager::disableCode($id);
                $this->respond(true, 'Código desativado: ninguém mais entra por ele no cadastro.', "/admin/instituicoes/{$id}");
            }
            $code = InstitutionManager::regenerateCode($id);
            $this->respond(true, "Novo código: {$code}. O anterior deixou de valer.", "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar o código', $e), "/admin/instituicoes/{$id}");
        }
    }
}
