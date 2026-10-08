<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Models\InstitutionMember;
use App\Services\ArchiveException;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/membros/{member}/remover — o vínculo vai para Dados excluídos. */
final class RemoveInstitutionMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $member = InstitutionMember::find((int) ($params['member'] ?? 0));
        if ($member === null || $member['institution_id'] !== $id) {
            $this->respond(false, 'Vínculo não encontrado.', "/admin/instituicoes/{$id}");
        }

        try {
            InstitutionManager::removeMember($member['id']);
            $this->respond(true, 'Vínculo removido (fica em Dados excluídos).', "/admin/instituicoes/{$id}");
        } catch (InstitutionException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o vínculo', $e), "/admin/instituicoes/{$id}");
        }
    }
}
