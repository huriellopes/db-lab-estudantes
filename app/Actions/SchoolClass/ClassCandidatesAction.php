<?php

declare(strict_types=1);

namespace App\Actions\SchoolClass;

use App\Core\Action;
use App\Core\Auth;
use App\Models\ClassMember;
use App\Models\SchoolClass;
use App\Services\ClassManager;

/** Autocomplete do "adicionar à turma" — só para quem pode editar a turma. GET /turmas/{id}/candidatos?q=. */
final class ClassCandidatesAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $class = SchoolClass::find((int) ($params['id'] ?? 0));
        $canEdit = $class !== null && ClassManager::canEdit($class, Auth::user());
        session_write_close();

        header('Content-Type: application/json');
        if (!$canEdit) {
            http_response_code(404);
            echo json_encode(['results' => []]);

            return;
        }
        echo json_encode(['results' => ClassMember::candidates($class->id, (string) ($_GET['q'] ?? ''))], JSON_UNESCAPED_UNICODE);
    }
}
