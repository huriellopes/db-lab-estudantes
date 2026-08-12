<?php

declare(strict_types=1);

use App\Support\Role;

it('only allows aluno to self-register', function () {
    expect(Role::registrable())->toBe(Role::Aluno);
});

it('has a human-readable label per case', function () {
    expect(Role::Aluno->label())->toBe('Aluno')
        ->and(Role::Professor->label())->toBe('Professor')
        ->and(Role::Admin->label())->toBe('Admin');
});

it('exposes its raw string value (enums can\'t implement __toString in PHP)', function () {
    expect(Role::Admin->value)->toBe('admin');
});

it('parses from the raw string value used in the database/forms', function () {
    expect(Role::tryFrom('professor'))->toBe(Role::Professor)
        ->and(Role::tryFrom('super-hacker'))->toBeNull();
});
