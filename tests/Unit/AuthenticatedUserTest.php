<?php

declare(strict_types=1);

use App\Support\AuthenticatedUser;
use App\Support\Role;

function userNamed(string $name): AuthenticatedUser
{
    return new AuthenticatedUser(1, $name, 'teste@example.com', Role::Aluno, 'u1_teste');
}

it('keeps only the first and last name when there are three or more parts', function () {
    expect(userNamed('Huriel Correia Lopes')->shortName())->toBe('Huriel Lopes');
});

it('keeps first and last name even with several middle names', function () {
    expect(userNamed('Maria da Silva Santos Souza')->shortName())->toBe('Maria Souza');
});

it('leaves a two-part name untouched', function () {
    expect(userNamed('Ana Souza')->shortName())->toBe('Ana Souza');
});

it('leaves a single-word name untouched', function () {
    expect(userNamed('Madonna')->shortName())->toBe('Madonna');
});

it('collapses extra whitespace between names', function () {
    expect(userNamed('  Huriel   Correia   Lopes  ')->shortName())->toBe('Huriel Lopes');
});
