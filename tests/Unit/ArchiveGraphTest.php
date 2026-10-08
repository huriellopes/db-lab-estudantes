<?php

declare(strict_types=1);

use App\Support\ArchiveGraph;

it('knows the archivable models and their tables', function () {
    expect(ArchiveGraph::MODELS)->toBe(['user', 'schema', 'saved_query', 'er_diagram'])
        ->and(ArchiveGraph::table('user'))->toBe('users')
        ->and(ArchiveGraph::table('schema'))->toBe('schemas_criados')
        ->and(ArchiveGraph::table('saved_query'))->toBe('saved_queries')
        ->and(ArchiveGraph::table('er_diagram'))->toBe('er_diagrams')
        ->and(ArchiveGraph::isKnown('remember_token'))->toBeFalse();
});

it('refuses unknown models instead of building SQL with them', function () {
    ArchiveGraph::table('users; DROP TABLE x');
})->throws(InvalidArgumentException::class);

it('takes a user\'s schemas, saved queries and diagrams along with it', function () {
    expect(ArchiveGraph::children('user'))->toBe(['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id'])
        ->and(ArchiveGraph::children('schema'))->toBe([])
        ->and(ArchiveGraph::children('saved_query'))->toBe([]);
});

it('builds readable labels', function () {
    expect(ArchiveGraph::label('user', ['name' => 'Maria Souza', 'email' => 'maria@x.com']))->toBe('Maria Souza <maria@x.com>')
        ->and(ArchiveGraph::label('schema', ['db_name' => 'maria__bio']))->toBe('maria__bio')
        ->and(ArchiveGraph::label('saved_query', ['title' => 'Top 10']))->toBe('Top 10')
        ->and(ArchiveGraph::label('er_diagram', ['title' => 'Loja']))->toBe('Loja')
        ->and(mb_strlen(ArchiveGraph::label('saved_query', ['title' => str_repeat('a', 300)])))->toBe(200)
        ->and(ArchiveGraph::typeLabel('saved_query'))->toBe('Consulta salva');
});
