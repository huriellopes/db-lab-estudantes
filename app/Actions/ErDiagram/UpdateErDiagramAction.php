<?php

declare(strict_types=1);

namespace App\Actions\ErDiagram;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\ErDiagram as ErDiagramEntity;
use App\Models\ErDiagram;
use App\Support\ErDiagramValidator;
use Throwable;

/** POST /laboratorio/modelagem/{id}. */
final class UpdateErDiagramAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;
        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        [$title, $data, $error] = ErDiagramValidator::validate(
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['data'] ?? ''),
        );
        if ($error !== null) {
            $this->json(false, $error);
        }

        try {
            ErDiagram::update($diagram->id, $title, $data);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('salvar o diagrama', $e));
        }

        $this->json(true, "Diagrama \"{$title}\" salvo.", [
            'id' => $diagram->id,
            'diagrams' => array_map(
                static fn (ErDiagramEntity $d): array => $d->toSummaryArray(),
                ErDiagram::allForUser(Auth::id()),
            ),
        ]);
    }
}
