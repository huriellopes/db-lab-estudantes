<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

/**
 * Guia de referência sobre SQL/NoSQL — conteúdo estático (fica no próprio Twig de cada
 * tópico, não no banco), pra iniciante/intermediário: o que é, pra que serve, quando usar
 * e exemplo. Cada slug é um template próprio em app/Views/guide/ — validado contra essa
 * lista fixa antes de renderizar, então não tem como virar um path traversal nem um 500
 * por template inexistente.
 */
final class GuideController extends Controller
{
    /** @var array<string, array{title: string, summary: string, icon: string}> */
    private const TOPICS = [
        'sql-ansi' => [
            'title' => 'SQL ANSI',
            'summary' => 'O padrão por trás de praticamente todo banco relacional.',
            'icon' => '📐',
        ],
        'mysql' => [
            'title' => 'MySQL',
            'summary' => 'O banco relacional usado neste laboratório.',
            'icon' => '🐬',
        ],
        'postgresql' => [
            'title' => 'PostgreSQL',
            'summary' => 'Relacional open-source, forte em conformidade e recursos avançados.',
            'icon' => '🐘',
        ],
        'oracle' => [
            'title' => 'Oracle Database',
            'summary' => 'Relacional corporativo, comum em grandes empresas.',
            'icon' => '🏛️',
        ],
        'sql-server' => [
            'title' => 'SQL Server',
            'summary' => 'Relacional da Microsoft, forte no ecossistema .NET/Windows.',
            'icon' => '🪟',
        ],
        'mongodb' => [
            'title' => 'MongoDB',
            'summary' => 'NoSQL orientado a documentos.',
            'icon' => '🍃',
        ],
        'redis' => [
            'title' => 'Redis',
            'summary' => 'Banco em memória, chave-valor — cache, filas, sessão.',
            'icon' => '⚡',
        ],
    ];

    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('guide/index', [
            'pageTitle' => 'Guia de bancos de dados',
            'topics' => self::TOPICS,
        ]);
    }

    public function show(array $params): void
    {
        Auth::requireLogin();

        $slug = (string) ($params['slug'] ?? '');
        if (!isset(self::TOPICS[$slug])) {
            http_response_code(404);
            $this->render('errors/404');

            return;
        }

        $this->render("guide/{$slug}", [
            'pageTitle' => self::TOPICS[$slug]['title'] . ' · Guia',
            'topics' => self::TOPICS,
            'currentSlug' => $slug,
        ]);
    }
}
