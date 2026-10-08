<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** O professor logado sai da turma (não "se remove"). POST /turmas/{id}/sair. */
final class LeaveClassAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            ClassManager::leave($id, Auth::user());
            $this->respond(true, 'Você saiu da turma.', '/turmas');
        } catch (ClassException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('sair da turma', $e), "/turmas/{$id}");
        }
    }
}
