<?php

declare(strict_types=1);

use App\Support\Paginator;

it('reports a single last page when total fits within one page', function () {
    $paginator = new Paginator(items: [1, 2, 3], page: 1, perPage: 10, total: 3);

    expect($paginator->lastPage())->toBe(1)
        ->and($paginator->hasPrevious())->toBeFalse()
        ->and($paginator->hasNext())->toBeFalse();
});

it('computes lastPage with a remainder page', function () {
    $paginator = new Paginator(items: [], page: 1, perPage: 10, total: 23);

    expect($paginator->lastPage())->toBe(3);
});

it('computes lastPage exactly when total is a multiple of perPage', function () {
    $paginator = new Paginator(items: [], page: 1, perPage: 10, total: 20);

    expect($paginator->lastPage())->toBe(2);
});

it('treats an empty result set as a single (empty) page, never zero', function () {
    $paginator = new Paginator(items: [], page: 1, perPage: 10, total: 0);

    expect($paginator->lastPage())->toBe(1)
        ->and($paginator->from())->toBe(0)
        ->and($paginator->to())->toBe(0);
});

it('flags hasPrevious/hasNext correctly on a middle page', function () {
    $paginator = new Paginator(items: [], page: 2, perPage: 10, total: 25);

    expect($paginator->hasPrevious())->toBeTrue()
        ->and($paginator->hasNext())->toBeTrue();
});

it('computes the from/to range shown to the user', function () {
    $paginator = new Paginator(items: [], page: 2, perPage: 10, total: 23);

    expect($paginator->from())->toBe(11)
        ->and($paginator->to())->toBe(20);
});

it('caps "to" at the total on the last, partial page', function () {
    $paginator = new Paginator(items: [], page: 3, perPage: 10, total: 23);

    expect($paginator->from())->toBe(21)
        ->and($paginator->to())->toBe(23)
        ->and($paginator->hasNext())->toBeFalse();
});
