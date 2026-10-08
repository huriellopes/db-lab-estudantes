<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Models\ClassMember;
use App\Services\ArchiveException;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas/{id}/membros/{member}/remover — o vínculo vai para Dados excluídos. */
final class RemoveClassMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);
        $member = ClassMember::find((int) ($params['member'] ?? 0));
        if ($member === null || $member['class_id'] !== $id) {
            $this->respond(false, 'Vínculo não encontrado.', "/turmas/{$id}");
        }

        try {
            ClassManager::removeMember($member['id'], Auth::user());
            $this->respond(true, 'Removido da turma (fica em Dados excluídos).', "/turmas/{$id}");
        } catch (ClassException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover da turma', $e), "/turmas/{$id}");
        }
    }
}
