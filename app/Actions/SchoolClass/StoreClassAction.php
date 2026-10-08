<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas — form comum (boosted): cria e abre a turma. */
final class StoreClassAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        try {
            $id = ClassManager::create((int) ($_POST['institution_id'] ?? 0), (string) ($_POST['name'] ?? ''), Auth::user());
            $this->respond(true, 'Turma criada. Agora vincule os alunos.', "/turmas/{$id}");
        } catch (ClassException $e) {
            $this->respond(false, $e->getMessage(), '/turmas');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('criar a turma', $e), '/turmas');
        }
    }
}
