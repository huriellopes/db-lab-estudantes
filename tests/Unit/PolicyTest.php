<?php

use App\Support\Policy;

function userWithRole(?string $role): ?array
{
    return $role === null ? null : ['id' => 1, 'name' => 'Teste', 'role' => $role, 'mysql_login' => 'u1_teste'];
}

it('identifies each role correctly', function () {
    expect(Policy::isAluno(userWithRole('aluno')))->toBeTrue()
        ->and(Policy::isProfessor(userWithRole('professor')))->toBeTrue()
        ->and(Policy::isAdmin(userWithRole('admin')))->toBeTrue()
        ->and(Policy::isAdmin(userWithRole('aluno')))->toBeFalse();
});

it('handles a null (guest) user without error', function () {
    expect(Policy::isAdmin(null))->toBeFalse()
        ->and(Policy::isProfessor(null))->toBeFalse()
        ->and(Policy::canManageStudents(null))->toBeFalse();
});

it('lets professors and admins manage students, but not alunos', function () {
    expect(Policy::canManageStudents(userWithRole('professor')))->toBeTrue()
        ->and(Policy::canManageStudents(userWithRole('admin')))->toBeTrue()
        ->and(Policy::canManageStudents(userWithRole('aluno')))->toBeFalse();
});

it('restricts full user/schema management to admins only', function () {
    expect(Policy::canManageAllUsers(userWithRole('admin')))->toBeTrue()
        ->and(Policy::canManageAllUsers(userWithRole('professor')))->toBeFalse()
        ->and(Policy::canManageAllSchemas(userWithRole('admin')))->toBeTrue()
        ->and(Policy::canManageAllSchemas(userWithRole('aluno')))->toBeFalse();
});

it('validates known roles only', function () {
    expect(Policy::isValidRole('aluno'))->toBeTrue()
        ->and(Policy::isValidRole('professor'))->toBeTrue()
        ->and(Policy::isValidRole('admin'))->toBeTrue()
        ->and(Policy::isValidRole('super-hacker'))->toBeFalse();
});
