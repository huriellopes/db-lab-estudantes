<?php

declare(strict_types=1);

use App\Support\ClassAccess;
use App\Support\Role;

it('lets admins and professors of the institution see a class, and students only their own', function () {
    expect(ClassAccess::canView(Role::Admin, [], 3, false))->toBeTrue()
        ->and(ClassAccess::canView(Role::Professor, [1, 3], 3, false))->toBeTrue()
        ->and(ClassAccess::canView(Role::Professor, [1], 3, false))->toBeFalse()
        ->and(ClassAccess::canView(Role::Aluno, [3], 3, true))->toBeTrue()
        ->and(ClassAccess::canView(Role::Aluno, [3], 3, false))->toBeFalse();
});

it('lets only responsible professors and admins edit', function () {
    expect(ClassAccess::canEdit(Role::Admin, 1, []))->toBeTrue()
        ->and(ClassAccess::canEdit(Role::Professor, 7, [7, 9]))->toBeTrue()
        ->and(ClassAccess::canEdit(Role::Professor, 8, [7, 9]))->toBeFalse()
        ->and(ClassAccess::canEdit(Role::Aluno, 7, [7]))->toBeFalse();
});
