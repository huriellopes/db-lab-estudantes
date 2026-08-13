<?php

declare(strict_types=1);

namespace App\Actions\ErDiagram;

use App\Core\Action;
use App\Core\Auth;
use App\Models\ErDiagram;

/**
 * Devolve um diagrama específico em JSON (o índice só manda id/título/data resumida, não o
 * desenho inteiro). GET /laboratorio/modelagem/{id}.
 */
final class ShowErDiagramAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;

        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        $decoded = json_decode($diagram->data, associative: true);
        $this->json(true, '', [
            'diagram' => [
                'id' => $diagram->id,
                'title' => $diagram->title,
                'data' => is_array($decoded) ? $decoded : ['entities' => [], 'relationships' => []],
            ],
        ]);
    }
}
