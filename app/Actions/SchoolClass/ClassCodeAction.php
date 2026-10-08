<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ClassException;
use App\Services\ClassManager;
use Throwable;

/** POST /turmas/{id}/codigo — acao=gerar|desativar (responsáveis e admin). */
final class ClassCodeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            if (($_POST['acao'] ?? '') === 'desativar') {
                ClassManager::disableCode($id, Auth::user());
                $this->respond(true, 'Código desativado: ninguém mais entra na turma por ele.', "/turmas/{$id}");
            }
            $code = ClassManager::regenerateCode($id, Auth::user());
            $this->respond(true, "Novo código da turma: {$code}. O anterior deixou de valer.", "/turmas/{$id}");
        } catch (ClassException $e) {
            $this->respond(false, $e->getMessage(), "/turmas/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar o código', $e), "/turmas/{$id}");
        }
    }
}
