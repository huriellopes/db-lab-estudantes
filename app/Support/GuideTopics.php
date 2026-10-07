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
    /**
     * Ordem = trilha de estudo sugerida (a página inicial do guia numera nessa ordem).
     *
     * @var array<string, array{title: string, summary: string, icon: string, group: string}>
     */
    public const ALL = [
        'modelagem-er' => [
            'title' => 'Modelagem de dados (MER/DER)',
            'summary' => 'Desenhe o banco no papel antes de criar tabelas: entidades, atributos e relacionamentos.',
            'icon' => '🧩',
            'group' => 'Fundamentos',
        ],
        'formas-normais' => [
            'title' => 'Formas Normais (1FN, 2FN, 3FN)',
            'summary' => 'Organize as tabelas pra não repetir dado nem deixar informação contraditória.',
            'icon' => '🧹',
            'group' => 'Fundamentos',
        ],
        'sql-ansi' => [
            'title' => 'SQL ANSI',
            'summary' => 'A linguagem padrão dos bancos relacionais: consultar, inserir, juntar e resumir dados.',
            'icon' => '📐',
            'group' => 'Fundamentos',
        ],
        'mysql' => [
            'title' => 'MySQL',
            'summary' => 'O banco que você usa neste laboratório — com exemplos que rodam direto no console.',
            'icon' => '🐬',
            'group' => 'Bancos relacionais',
        ],
        'postgresql' => [
            'title' => 'PostgreSQL',
            'summary' => 'Relacional open-source cheio de recursos: JSONB, arrays, RETURNING, extensões.',
            'icon' => '🐘',
            'group' => 'Bancos relacionais',
        ],
        'sql-server' => [
            'title' => 'SQL Server',
            'summary' => 'O relacional da Microsoft e seu dialeto, o T-SQL.',
            'icon' => '🪟',
            'group' => 'Bancos relacionais',
        ],
        'oracle' => [
            'title' => 'Oracle Database',
            'summary' => 'O relacional corporativo de grandes empresas, com PL/SQL.',
            'icon' => '🏛️',
            'group' => 'Bancos relacionais',
        ],
        'mongodb' => [
            'title' => 'MongoDB',
            'summary' => 'NoSQL de documentos: dados guardados como JSON, sem esquema fixo.',
            'icon' => '🍃',
            'group' => 'NoSQL',
        ],
        'redis' => [
            'title' => 'Redis',
            'summary' => 'Banco em memória, chave-valor e muito rápido: cache, sessões, filas e ranking.',
            'icon' => '⚡',
            'group' => 'NoSQL',
        ],
    ];
}
