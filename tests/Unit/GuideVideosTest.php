<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\GuideTopics;
use App\Support\GuideVideos;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/dblab-videos-' . bin2hex(random_bytes(4));
    mkdir($this->dir, 0777, true);
    GuideVideos::useDirectory($this->dir);
});

afterEach(function () {
    GuideVideos::useDirectory(null);
    exec('rm -rf ' . escapeshellarg($this->dir));
});

/** Põe no volume de teste os arquivos de um vídeo declarado. */
function uploadVideo(string $dir, string $arquivo, array $exts = ['mp4', 'vtt', 'webp']): void
{
    foreach ($exts as $ext) {
        touch("{$dir}/{$arquivo}.{$ext}");
    }
}

it('declares every video against an existing topic, with a unique safe file name', function () {
    $arquivos = array_column(GuideVideos::ALL, 'arquivo');

    expect($arquivos)->toBe(array_values(array_unique($arquivos)));
    foreach (GuideVideos::ALL as $video) {
        expect(GuideTopics::ALL)->toHaveKey($video['topic'])
            ->and($video['arquivo'])->toMatch('/^[a-z0-9-]+$/')
            ->and($video['duration'])->toMatch('/^\d+:\d{2}$/');
    }
});

it('only lists a video once its mp4 and captions are uploaded', function () {
    $video = GuideVideos::ALL[0];
    expect(GuideVideos::available())->toBe([]);

    uploadVideo($this->dir, $video['arquivo'], ['mp4']);
    expect(GuideVideos::available())->toBe([]);

    uploadVideo($this->dir, $video['arquivo'], ['vtt']);
    expect(GuideVideos::available())->toHaveCount(1)
        ->and(GuideVideos::available()[0]['hasPoster'])->toBeFalse()
        ->and(GuideVideos::available($video['topic']))->toHaveCount(1)
        ->and(GuideVideos::available('redis'))->toBe([])
        ->and(GuideVideos::countByTopic()[$video['topic']])->toBe(1 + count(GuideVideos::youtube($video['topic'])));
});

it('changes the video version when a file is replaced, so the browser cache does not keep the old one', function () {
    $video = GuideVideos::ALL[0];
    uploadVideo($this->dir, $video['arquivo']);
    touch("{$this->dir}/{$video['arquivo']}.mp4", 1_700_000_000);
    touch("{$this->dir}/{$video['arquivo']}.vtt", 1_700_000_000);
    touch("{$this->dir}/{$video['arquivo']}.webp", 1_700_000_000);
    clearstatcache();
    expect(GuideVideos::available()[0]['version'])->toBe(1_700_000_000);

    touch("{$this->dir}/{$video['arquivo']}.mp4", 1_800_000_000);
    clearstatcache();
    expect(GuideVideos::available()[0]['version'])->toBe(1_800_000_000);
});

it('only serves files that belong to a declared video', function () {
    $arquivo = GuideVideos::ALL[0]['arquivo'];

    expect(GuideVideos::contentType("{$arquivo}.mp4"))->toBe('video/mp4')
        ->and(GuideVideos::contentType("{$arquivo}.vtt"))->toBe('text/vtt; charset=utf-8')
        ->and(GuideVideos::contentType("{$arquivo}.webp"))->toBe('image/webp')
        ->and(GuideVideos::contentType("{$arquivo}.php"))->toBeNull()
        ->and(GuideVideos::contentType("{$arquivo}"))->toBeNull()
        ->and(GuideVideos::contentType('outro.mp4'))->toBeNull()
        ->and(GuideVideos::contentType("../{$arquivo}.mp4"))->toBeNull()
        ->and(GuideVideos::contentType('..%2F.env'))->toBeNull();
});

it('shows the video box with player and captions on the topic page', function () {
    $video = GuideVideos::ALL[0];
    uploadVideo($this->dir, $video['arquivo']);

    $html = View::render("guide/{$video['topic']}", [
        'topics' => GuideTopics::ALL,
        'currentSlug' => $video['topic'],
        'pageTitle' => 'Guia',
        'topicVideos' => GuideVideos::available($video['topic']),
    ]);

    expect($html)
        ->toContain('▶ Ver em poucos minutos')
        ->toContain('🎬 Aprenda vendo')
        ->toContain("src=\"/guia/video/{$video['arquivo']}.mp4?v=")
        ->toContain("<track kind=\"captions\" src=\"/guia/video/{$video['arquivo']}.vtt?v=")
        ->toContain("poster=\"/guia/video/{$video['arquivo']}.webp?v=")
        ->toContain('preload="none"');
});

it('leaves the topic page without a video box when there is no video', function () {
    $html = View::render('guide/redis', ['topics' => GuideTopics::ALL, 'currentSlug' => 'redis', 'pageTitle' => 'Guia', 'topicVideos' => []]);

    expect($html)->not->toContain('<video')->not->toContain('Ver em poucos minutos');
});

it('lists the available videos in trail order on /guia/videos', function () {
    uploadVideo($this->dir, GuideVideos::ALL[3]['arquivo']);
    uploadVideo($this->dir, GuideVideos::ALL[0]['arquivo']);

    $html = View::render('guide/videos', [
        'topics' => GuideTopics::ALL,
        'videos' => GuideVideos::available(),
        'plannedCount' => count(GuideVideos::ALL),
        'youtube' => [],
    ]);

    expect(strpos($html, GuideVideos::ALL[0]['title']))->toBeLessThan(strpos($html, GuideVideos::ALL[3]['title']))
        ->and($html)->toContain('/guia/' . GuideVideos::ALL[3]['topic'])
        ->and($html)->not->toContain(GuideVideos::ALL[1]['title']);
});

it('tells the student the videos are coming when none is uploaded yet', function () {
    $html = View::render('guide/videos', ['topics' => GuideTopics::ALL, 'videos' => [], 'plannedCount' => 5, 'youtube' => []]);

    expect($html)->toContain('Os vídeos estão sendo gravados')->not->toContain('<video');
});

it('shows the video count badge only on topics that have videos', function () {
    $html = View::render('guide/index', ['topics' => GuideTopics::ALL, 'pageTitle' => 'Guia', 'videoCounts' => ['mysql' => 1, 'redis' => 3]]);

    expect($html)->toContain('🎬 1 vídeo<')
        ->toContain('🎬 3 vídeos')
        ->toContain('/guia/videos')
        ->and(substr_count($html, '🎬 '))->toBe(3); // 2 selos + o link da trilha
});

it('declares YouTube recommendations with valid ids for existing topics, every topic covered', function () {
    foreach (GuideVideos::YOUTUBE as $video) {
        expect(GuideTopics::ALL)->toHaveKey($video['topic'])
            ->and(isset($video['id']) xor isset($video['list']))->toBeTrue();
        if (isset($video['id'])) {
            expect($video['id'])->toMatch('/^[A-Za-z0-9_-]{11}$/');
        } else {
            expect($video['list'])->toMatch('/^PL[A-Za-z0-9_-]{16,32}$/');
        }
    }

    expect(array_diff(array_keys(GuideTopics::ALL), array_column(GuideVideos::YOUTUBE, 'topic')))->toBe([]);
});

it('builds privacy-friendly embed urls, in trail order', function () {
    $videos = GuideVideos::youtube();
    $order = array_keys(GuideTopics::ALL);

    foreach ($videos as $video) {
        expect($video['embed'])->toStartWith('https://www.youtube-nocookie.com/embed/')
            ->and($video['watch'])->toStartWith('https://www.youtube.com/');
    }
    $positions = array_map(fn ($v) => array_search($v['topic'], $order, true), $videos);
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted)
        ->and(GuideVideos::youtube('mysql')[0]['embed'])->toContain('videoseries?list=PLHz_AreHm4dkBs-795Dsgvau_ekxg8g1r&autoplay=1');
});

it('only creates the YouTube iframe after the click (inside an x-if template)', function () {
    $html = View::render('guide/redis', [
        'topics' => GuideTopics::ALL, 'currentSlug' => 'redis', 'pageTitle' => 'Guia',
        'topicVideos' => [], 'topicYoutube' => GuideVideos::youtube('redis'),
    ]);

    expect($html)->toContain('Recomendados no YouTube')
        ->toContain('Código Fonte TV')
        ->and(preg_replace('#<template x-if="aberto">.*?</template>#s', '', $html))->not->toContain('<iframe');
});

it('lists the YouTube recommendations grouped by topic on /guia/videos', function () {
    $html = View::render('guide/videos', [
        'topics' => GuideTopics::ALL, 'videos' => [], 'plannedCount' => 5, 'youtube' => GuideVideos::youtube(),
    ]);

    foreach (GuideTopics::ALL as $slug => $topic) {
        expect($html)->toContain("/guia/{$slug}");
    }
    expect($html)->toContain('Curso em Vídeo')->toContain('Os vídeos estão sendo gravados');
});
