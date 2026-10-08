<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas/{id}/membros — identificador = e-mail ou username. */
final class AddClassMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            $user = ClassManager::addMember($id, (string) ($_POST['identificador'] ?? ''), Auth::user());
            $what = $user->role->value === 'professor' ? 'como professor responsável' : 'à turma';
            $this->respond(true, "{$user->name} adicionado {$what}.", "/turmas/{$id}");
        } catch (ClassException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('adicionar à turma', $e), "/turmas/{$id}");
        }
    }
}
