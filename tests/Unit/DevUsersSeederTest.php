<?php

declare(strict_types=1);

use App\Support\MysqlIdentifier;
use App\Support\Role;
use Database\Seeders\DevUsersSeeder;

it('only treats an explicit local environment as local', function () {
    expect(DevUsersSeeder::isLocal('local'))->toBeTrue()
        ->and(DevUsersSeeder::isLocal(' Local '))->toBeTrue()
        ->and(DevUsersSeeder::isLocal('development'))->toBeTrue()
        ->and(DevUsersSeeder::isLocal('production'))->toBeFalse()
        ->and(DevUsersSeeder::isLocal('staging'))->toBeFalse()
        ->and(DevUsersSeeder::isLocal(''))->toBeFalse()
        ->and(DevUsersSeeder::isLocal(null))->toBeFalse();
});

it('seeds one account per role, with valid and distinct MySQL logins', function () {
    $users = DevUsersSeeder::USERS;

    expect(array_map(static fn (array $u): Role => $u['role'], $users))->toEqualCanonicalizing(Role::cases())
        ->and(array_unique(array_column($users, 'username')))->toHaveCount(count($users))
        ->and(array_unique(array_column($users, 'email')))->toHaveCount(count($users));

    foreach ($users as $user) {
        expect(MysqlIdentifier::isValidCustomLogin($user['username']))->toBeTrue();
    }
});
