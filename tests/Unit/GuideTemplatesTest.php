<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\GuideTopics;

/**
 * Renderiza de verdade cada página do guia (Twig), pra um erro de sintaxe ou um embed
 * quebrado não chegar até o aluno. A correção dos exemplos em si é conferida pelo
 * bin/validate-guide-examples.php, que roda cada um no banco correspondente.
 */
function renderGuide(string $slug): string
{
    return View::render("guide/{$slug}", [
        'topics' => GuideTopics::ALL,
        'currentSlug' => $slug,
        'pageTitle' => 'Guia',
    ]);
}

it('renders every guide topic with the three levels', function (string $slug) {
    $html = renderGuide($slug);

    expect($html)
        ->toContain(GuideTopics::ALL[$slug]['title'])
        ->toContain("level === 'iniciante'")
        ->toContain("level === 'intermediario'")
        ->toContain("level === 'avancado'");
})->with(array_keys(GuideTopics::ALL));

it('never leaves a result placeholder unfilled', function (string $slug) {
    expect(renderGuide($slug))->not->toContain('PENDENTE');
})->with(array_keys(GuideTopics::ALL));

it('lists every topic on the guide index', function () {
    $html = renderGuide('index');

    foreach (GuideTopics::ALL as $slug => $topic) {
        expect($html)->toContain("/guia/{$slug}");
    }
});

it('only offers "Testar no console" on MySQL examples (the lab console is MySQL)', function (string $slug) {
    preg_match_all('/data-engine="([a-z]+)"(?:(?!<\/figure>).)*?openInConsole/s', renderGuide($slug), $matches);

    expect(array_values(array_diff(array_unique($matches[1]), ['mysql'])))->toBe([]);
})->with(array_keys(GuideTopics::ALL));
