<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Core\Action;
use App\Core\Auth;
use App\Support\GuideTopics;

/**
 * Página de um tópico do guia. GET /guia/{slug} — validado contra a lista fixa de
 * App\Support\GuideTopics antes de renderizar, então não tem como virar um path traversal
 * nem um 500 por template inexistente.
 */
final class ShowGuideTopicAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $slug = (string) ($params['slug'] ?? '');
        if (!isset(GuideTopics::ALL[$slug])) {
            http_response_code(404);
            $this->render('errors/404');

            return;
        }

        $this->render("guide/{$slug}", [
            'pageTitle' => GuideTopics::ALL[$slug]['title'] . ' · Guia',
            'topics' => GuideTopics::ALL,
            'currentSlug' => $slug,
        ]);
    }
}
