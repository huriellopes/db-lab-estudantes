<?php

declare(strict_types=1);

use App\Support\AuthenticatedUser;
use App\Support\Policy;
use App\Support\Role;

function userWithRole(?Role $role): ?AuthenticatedUser
{
    return $role === null ? null : new AuthenticatedUser(1, 'Teste', 'teste@example.com', $role, 'u1_teste');
}

it('identifies each role correctly', function () {
    expect(Policy::isAluno(userWithRole(Role::Aluno)))->toBeTrue()
        ->and(Policy::isProfessor(userWithRole(Role::Professor)))->toBeTrue()
        ->and(Policy::isAdmin(userWithRole(Role::Admin)))->toBeTrue()
        ->and(Policy::isAdmin(userWithRole(Role::Aluno)))->toBeFalse();
});

it('handles a null (guest) user without error', function () {
    expect(Policy::isAdmin(null))->toBeFalse()
        ->and(Policy::isProfessor(null))->toBeFalse()
        ->and(Policy::canManageStudents(null))->toBeFalse();
});

it('lets professors and admins manage students, but not alunos', function () {
    expect(Policy::canManageStudents(userWithRole(Role::Professor)))->toBeTrue()
        ->and(Policy::canManageStudents(userWithRole(Role::Admin)))->toBeTrue()
        ->and(Policy::canManageStudents(userWithRole(Role::Aluno)))->toBeFalse();
});

it('restricts full user/schema management to admins only', function () {
    expect(Policy::canManageAllUsers(userWithRole(Role::Admin)))->toBeTrue()
        ->and(Policy::canManageAllUsers(userWithRole(Role::Professor)))->toBeFalse()
        ->and(Policy::canManageAllSchemas(userWithRole(Role::Admin)))->toBeTrue()
        ->and(Policy::canManageAllSchemas(userWithRole(Role::Aluno)))->toBeFalse();
});
