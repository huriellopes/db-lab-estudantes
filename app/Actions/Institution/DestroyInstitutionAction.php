<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/excluir — vai para Dados excluídos com os vínculos. */
final class DestroyInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            InstitutionManager::delete($id);
            $this->respond(true, 'Instituição movida para Dados excluídos (com os vínculos). As contas continuam ativas.', '/admin/instituicoes');
        } catch (InstitutionException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a instituição', $e), "/admin/instituicoes/{$id}");
        }
    }
}
