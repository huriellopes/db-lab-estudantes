<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Core\Action;
use App\Core\Auth;
use App\Support\GuideTopics;
use App\Support\GuideVideos;

/** "Trilha em vídeo": todas as vídeo-aulas do guia em sequência. GET /guia/videos. */
final class ShowGuideVideosAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('guide/videos', [
            'pageTitle' => 'Trilha em vídeo · Guia',
            'topics' => GuideTopics::ALL,
            'videos' => GuideVideos::available(),
            'plannedCount' => count(GuideVideos::ALL),
            'youtube' => GuideVideos::youtube(),
        ]);
    }
}
