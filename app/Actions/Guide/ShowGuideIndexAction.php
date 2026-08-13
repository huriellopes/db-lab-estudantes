<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Core\Action;
use App\Core\Auth;
use App\Support\GuideTopics;

/** Índice do guia de referência (SQL/NoSQL) — lista de cards, um por tópico. GET /guia. */
final class ShowGuideIndexAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('guide/index', [
            'pageTitle' => 'Guia de bancos de dados',
            'topics' => GuideTopics::ALL,
        ]);
    }
}
