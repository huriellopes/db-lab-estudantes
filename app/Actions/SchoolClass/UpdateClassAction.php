<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas/{id} — renomear. */
final class UpdateClassAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            ClassManager::rename($id, (string) ($_POST['name'] ?? ''), Auth::user());
            $this->respond(true, 'Nome atualizado.', "/turmas/{$id}");
        } catch (ClassException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('renomear a turma', $e), "/turmas/{$id}");
        }
    }
}
