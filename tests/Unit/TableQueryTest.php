<?php

declare(strict_types=1);

use App\Support\TableQuery;

it('defaults to empty search, no sort, asc direction and page 1', function () {
    $query = TableQuery::fromParams([], ['name', 'email']);

    expect($query->search)->toBe('')
        ->and($query->sort)->toBeNull()
        ->and($query->direction)->toBe('asc')
        ->and($query->page)->toBe(1);
});

it('reads search, sort, direction and page from the params', function () {
    $query = TableQuery::fromParams(['q' => ' joão ', 'sort' => 'email', 'dir' => 'desc', 'page' => '3'], ['name', 'email']);

    expect($query->search)->toBe('joão')
        ->and($query->sort)->toBe('email')
        ->and($query->direction)->toBe('desc')
        ->and($query->page)->toBe(3);
});

it('falls back to the default sort when the requested key is not allowed', function () {
    $query = TableQuery::fromParams(['sort' => 'password_hash'], ['name', 'email'], 'name');

    expect($query->sort)->toBe('name');
});

it('ignores an allowed-looking sort key when no allowlist entry matches', function () {
    $query = TableQuery::fromParams(['sort' => 'unknown'], ['name', 'email']);

    expect($query->sort)->toBeNull();
});

it('clamps page to a minimum of 1', function () {
    expect(TableQuery::fromParams(['page' => '0'], [])->page)->toBe(1)
        ->and(TableQuery::fromParams(['page' => '-5'], [])->page)->toBe(1)
        ->and(TableQuery::fromParams(['page' => 'abc'], [])->page)->toBe(1);
});

it('only accepts the literal string desc as direction, anything else is asc', function () {
    expect(TableQuery::fromParams(['dir' => 'desc'], [])->direction)->toBe('desc')
        ->and(TableQuery::fromParams(['dir' => 'DESC'], [])->direction)->toBe('asc')
        ->and(TableQuery::fromParams(['dir' => 'anything'], [])->direction)->toBe('asc');
});

it('toggles the next sort direction: asc when switching columns, flipped when same column', function () {
    $query = TableQuery::fromParams(['sort' => 'name', 'dir' => 'asc'], ['name', 'email']);

    expect($query->nextDirectionFor('name'))->toBe('desc')
        ->and($query->nextDirectionFor('email'))->toBe('asc')
        ->and($query->isSortedBy('name'))->toBeTrue()
        ->and($query->isSortedBy('email'))->toBeFalse();
});

it('builds a clean url when everything is at its default', function () {
    $query = TableQuery::fromParams([], ['name']);

    expect($query->urlWith([]))->toBe('');
});

it('omits page=1 and dir=asc from the url but keeps other overrides', function () {
    $query = TableQuery::fromParams([], ['name']);

    expect($query->urlWith(['sort' => 'name', 'page' => 1, 'dir' => 'asc']))->toBe('?sort=name');
});

it('preserves the current search and sort when overriding only the page', function () {
    $query = TableQuery::fromParams(['q' => 'ana', 'sort' => 'name', 'dir' => 'desc'], ['name']);

    $url = $query->urlWith(['page' => 2]);

    expect($url)->toContain('q=ana')
        ->and($url)->toContain('sort=name')
        ->and($url)->toContain('dir=desc')
        ->and($url)->toContain('page=2');
});
