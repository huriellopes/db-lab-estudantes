<?php

declare(strict_types=1);

namespace App\Actions\ErDiagram;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\ErDiagram as ErDiagramEntity;
use App\Models\ErDiagram;
use App\Services\Archiver;
use Throwable;

/** POST /laboratorio/modelagem/{id}/excluir. */
final class DestroyErDiagramAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;

        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        try {
            Archiver::archive('er_diagram', $diagram->id, 'er_diagram.deleted');
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('excluir o diagrama', $e));
        }

        $this->json(true, "Diagrama \"{$diagram->title}\" excluído.", [
            'diagrams' => array_map(
                static fn (ErDiagramEntity $d): array => $d->toSummaryArray(),
                ErDiagram::allForUser(Auth::id()),
            ),
        ]);
    }
}
