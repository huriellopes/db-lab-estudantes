<?php

declare(strict_types=1);

use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\Archiver;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('keeps e-mail, login, prefix and schema names reserved while the batch is archived', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    expect(User::emailExists($user->email))->toBeTrue()
        ->and(User::isLoginTaken($user->mysqlLogin))->toBeTrue()
        ->and(SchemaRecord::nameTaken($db))->toBeTrue();

    Archiver::purge($batch);

    expect(User::emailExists($user->email))->toBeFalse()
        ->and(User::isLoginTaken($user->mysqlLogin))->toBeFalse()
        ->and(SchemaRecord::nameTaken($db))->toBeFalse();
});
