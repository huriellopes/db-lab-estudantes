<?php

declare(strict_types=1);

use App\Support\ArchiveSnapshot;

it('masks secrets and shortens long values only for display', function () {
    $display = ArchiveSnapshot::forDisplay([
        'name' => 'Maria',
        'password_hash' => '$2y$10$abc',
        'remember_token' => 'xyz',
        'data' => str_repeat('d', 400),
        'active' => 1,
        'last_login_at' => null,
    ]);

    expect($display['name'])->toBe('Maria')
        ->and($display['password_hash'])->toBe('[omitido]')
        ->and($display['remember_token'])->toBe('[omitido]')
        ->and(mb_strlen((string) $display['data']))->toBe(301)
        ->and($display['active'])->toBe(1)
        ->and($display['last_login_at'])->toBeNull();
});
