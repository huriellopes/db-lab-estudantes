<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas/{id}/excluir — vai para Dados excluídos com os vínculos. */
final class DestroyClassAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            ClassManager::delete($id, Auth::user());
            $this->respond(true, 'Turma movida para Dados excluídos (um admin pode restaurar).', '/turmas');
        } catch (ClassException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a turma', $e), "/turmas/{$id}");
        }
    }
}
