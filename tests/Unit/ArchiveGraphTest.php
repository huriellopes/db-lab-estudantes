<?php

declare(strict_types=1);

use App\Support\ArchiveGraph;

it('knows the archivable models and their tables', function () {
    expect(ArchiveGraph::MODELS)->toBe(['user', 'schema', 'saved_query', 'er_diagram', 'institution', 'institution_member'])
        ->and(ArchiveGraph::table('user'))->toBe('users')
        ->and(ArchiveGraph::table('schema'))->toBe('schemas_criados')
        ->and(ArchiveGraph::table('saved_query'))->toBe('saved_queries')
        ->and(ArchiveGraph::table('er_diagram'))->toBe('er_diagrams')
        ->and(ArchiveGraph::table('institution'))->toBe('institutions')
        ->and(ArchiveGraph::table('institution_member'))->toBe('institution_members')
        ->and(ArchiveGraph::isKnown('remember_token'))->toBeFalse();
});

it('refuses unknown models instead of building SQL with them', function () {
    ArchiveGraph::table('users; DROP TABLE x');
})->throws(InvalidArgumentException::class);

it('takes dependents along: a user\'s data and memberships, an institution\'s memberships', function () {
    expect(ArchiveGraph::children('user'))->toBe(['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id', 'institution_member' => 'user_id'])
        ->and(ArchiveGraph::children('institution'))->toBe(['institution_member' => 'institution_id'])
        ->and(ArchiveGraph::children('schema'))->toBe([])
        ->and(ArchiveGraph::label('institution', ['name' => 'Escola Azul']))->toBe('Escola Azul')
        ->and(ArchiveGraph::typeLabel('institution_member'))->toBe('Vínculo com instituição');
});

it('builds readable labels', function () {
    expect(ArchiveGraph::label('user', ['name' => 'Maria Souza', 'email' => 'maria@x.com']))->toBe('Maria Souza <maria@x.com>')
        ->and(ArchiveGraph::label('schema', ['db_name' => 'maria__bio']))->toBe('maria__bio')
        ->and(ArchiveGraph::label('saved_query', ['title' => 'Top 10']))->toBe('Top 10')
        ->and(ArchiveGraph::label('er_diagram', ['title' => 'Loja']))->toBe('Loja')
        ->and(mb_strlen(ArchiveGraph::label('saved_query', ['title' => str_repeat('a', 300)])))->toBe(200)
        ->and(ArchiveGraph::typeLabel('saved_query'))->toBe('Consulta salva');
});
