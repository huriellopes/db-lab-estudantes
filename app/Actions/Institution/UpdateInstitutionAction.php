<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id} — renomear. */
final class UpdateInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            InstitutionManager::rename($id, (string) ($_POST['name'] ?? ''));
            $this->respond(true, 'Nome atualizado.', "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('renomear a instituição', $e), "/admin/instituicoes/{$id}");
        }
    }
}
