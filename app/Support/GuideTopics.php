<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lista fixa dos tópicos do guia de referência (ver App\Actions\Guide\*) — conteúdo
 * estático, cada slug tem um template próprio em app/Views/guide/. Extraída do controller
 * pra `ShowGuideIndexAction` e `ShowGuideTopicAction` (duas Single Actions, ver
 * App\Core\Action) compartilharem sem duplicar.
 */
final class GuideTopics
{
    /** @var array<string, array{title: string, summary: string, icon: string}> */
    public const ALL = [
        'modelagem-er' => [
            'title' => 'Modelagem de dados (MER/DER)',
            'summary' => 'Como desenhar entidades e relacionamentos antes de criar as tabelas.',
            'icon' => '🧩',
        ],
        'formas-normais' => [
            'title' => 'Formas Normais (1FN, 2FN, 3FN)',
            'summary' => 'Como organizar as tabelas pra evitar dado repetido e inconsistente.',
            'icon' => '🧹',
        ],
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
}
