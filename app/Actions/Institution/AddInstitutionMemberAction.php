<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/membros — identificador = e-mail ou username. */
final class AddInstitutionMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            $user = InstitutionManager::addMember($id, (string) ($_POST['identificador'] ?? ''));
            $this->respond(true, "{$user->name} vinculado como {$user->role->label()}.", "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('vincular a pessoa', $e), "/admin/instituicoes/{$id}");
        }
    }
}
