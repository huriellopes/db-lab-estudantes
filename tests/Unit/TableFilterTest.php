<?php

declare(strict_types=1);

use App\Support\TableFilter;
use App\Support\TableQuery;

/** @return list<array{name: string, age: int}> */
function tableFilterFixture(): array
{
    return [
        ['name' => 'Carla', 'age' => 30],
        ['name' => 'ana', 'age' => 25],
        ['name' => 'Bruno', 'age' => 40],
    ];
}

it('returns everything, unsorted, when the query is empty', function () {
    $query = TableQuery::fromParams([], ['name', 'age']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: ['name' => fn (array $row) => $row['name'], 'age' => fn (array $row) => $row['age']],
        perPage: 10,
    );

    expect($result->total)->toBe(3)
        ->and(array_column($result->items, 'name'))->toBe(['Carla', 'ana', 'Bruno']);
});

it('filters by a case-insensitive substring match on the search text', function () {
    // "AN" só é substring de "ana" (Carla e Bruno não contêm "an").
    $query = TableQuery::fromParams(['q' => 'AN'], ['name']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: [],
        perPage: 10,
    );

    expect(array_column($result->items, 'name'))->toBe(['ana'])
        ->and($result->total)->toBe(1);
});

it('sorts ascending by the requested accessor', function () {
    $query = TableQuery::fromParams(['sort' => 'age'], ['age']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: ['age' => fn (array $row) => $row['age']],
        perPage: 10,
    );

    expect(array_column($result->items, 'age'))->toBe([25, 30, 40]);
});

it('reverses the order when direction is desc', function () {
    $query = TableQuery::fromParams(['sort' => 'age', 'dir' => 'desc'], ['age']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: ['age' => fn (array $row) => $row['age']],
        perPage: 10,
    );

    expect(array_column($result->items, 'age'))->toBe([40, 30, 25]);
});

it('ignores a sort key that has no matching accessor', function () {
    $query = TableQuery::fromParams([], ['name'], defaultSort: 'name');
    // Simula uma allowlist que aceitou a chave mas o caller não passou o accessor correspondente.
    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: [],
        perPage: 10,
    );

    expect(array_column($result->items, 'name'))->toBe(['Carla', 'ana', 'Bruno']);
});

it('paginates: slices items and reports total from before slicing', function () {
    $query = TableQuery::fromParams(['sort' => 'name'], ['name']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: ['name' => fn (array $row) => mb_strtolower($row['name'])],
        perPage: 2,
    );

    expect($result->total)->toBe(3)
        ->and($result->page)->toBe(1)
        ->and(array_column($result->items, 'name'))->toBe(['ana', 'Bruno']);
});

it('clamps the requested page down when it lands past the last page after filtering', function () {
    // "a" filtra pra Carla e ana (Bruno não contém "a").
    $query = TableQuery::fromParams(['q' => 'a', 'page' => '99'], ['name']);

    $result = TableFilter::paginate(
        tableFilterFixture(),
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: [],
        perPage: 10,
    );

    expect($result->page)->toBe(1)
        ->and($result->total)->toBe(2);
});

it('combines search, sort and pagination together', function () {
    $items = [
        ['name' => 'Ana Silva', 'age' => 22],
        ['name' => 'Ana Costa', 'age' => 31],
        ['name' => 'Bruno Alves', 'age' => 28],
        ['name' => 'Ana Pereira', 'age' => 19],
    ];
    $query = TableQuery::fromParams(['q' => 'ana', 'sort' => 'age', 'page' => '2'], ['age']);

    $result = TableFilter::paginate(
        $items,
        $query,
        searchText: fn (array $row) => $row['name'],
        sortAccessors: ['age' => fn (array $row) => $row['age']],
        perPage: 2,
    );

    expect($result->total)->toBe(3)
        ->and($result->page)->toBe(2)
        ->and(array_column($result->items, 'name'))->toBe(['Ana Costa']);
});
