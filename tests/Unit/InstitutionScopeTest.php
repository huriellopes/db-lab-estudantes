<?php

declare(strict_types=1);

use App\Support\InstitutionScope;
use App\Support\Role;

it('lets the admin see every student, with or without institution', function () {
    expect(InstitutionScope::canSeeStudent(Role::Admin, [], null))->toBeTrue()
        ->and(InstitutionScope::canSeeStudent(Role::Admin, [], 4))->toBeTrue();
});

it('lets a professor see only students of a shared institution', function () {
    expect(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], 4))->toBeTrue()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], 2))->toBeFalse()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], null))->toBeFalse()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [], 4))->toBeFalse();
});

it('never lets a student manage students', function () {
    expect(InstitutionScope::canSeeStudent(Role::Aluno, [4], 4))->toBeFalse();
});
