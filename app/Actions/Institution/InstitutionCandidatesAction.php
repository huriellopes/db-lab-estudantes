<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Models\InstitutionMember;

/** Autocomplete do "vincular pessoa": quem ainda pode entrar nesta instituição. GET /admin/instituicoes/{id}/candidatos?q=. */
final class InstitutionCandidatesAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        // Só leitura: libera a sessão pra digitação rápida não enfileirar requisições.
        session_write_close();

        header('Content-Type: application/json');
        echo json_encode(['results' => InstitutionMember::candidates((int) ($params['id'] ?? 0), (string) ($_GET['q'] ?? ''))], JSON_UNESCAPED_UNICODE);
    }
}
