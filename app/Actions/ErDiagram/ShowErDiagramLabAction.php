<?php

declare(strict_types=1);

namespace App\Actions\ErDiagram;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\ErDiagram as ErDiagramEntity;
use App\Models\ErDiagram;

/**
 * Laboratório de modelagem (MER/DER): editor visual (ver Alpine `erLab` em
 * resources/js/app.js) onde a pessoa desenha entidades/atributos/relacionamentos
 * arrastando caixas — ver /guia/modelagem-er pra teoria por trás. GET /laboratorio/modelagem.
 */
final class ShowErDiagramLabAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('lab/modelagem', [
            'pageTitle' => 'Laboratório de modelagem',
            'diagrams' => array_map(
                static fn (ErDiagramEntity $d): array => $d->toSummaryArray(),
                ErDiagram::allForUser(Auth::id()),
            ),
        ]);
    }
}
