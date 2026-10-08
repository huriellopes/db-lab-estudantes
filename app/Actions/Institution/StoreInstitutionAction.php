<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes — form comum (boosted): cria e abre a página da instituição. */
final class StoreInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        try {
            $id = InstitutionManager::create((string) ($_POST['name'] ?? ''));
            $this->respond(true, 'Instituição criada. Compartilhe o código de convite com os alunos.', "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), '/admin/instituicoes');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('criar a instituição', $e), '/admin/instituicoes');
        }
    }
}
