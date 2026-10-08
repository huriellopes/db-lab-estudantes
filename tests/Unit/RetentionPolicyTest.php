<?php

declare(strict_types=1);

use App\Support\RetentionPolicy;

it('is off when the retention is zero or negative', function () {
    $deleted = new DateTimeImmutable('2026-01-01 10:00');

    expect(RetentionPolicy::expiresAt($deleted, 0, false))->toBeNull()
        ->and(RetentionPolicy::expiresAt($deleted, -5, false))->toBeNull()
        ->and(RetentionPolicy::daysLeft($deleted, 0, false, new DateTimeImmutable('2030-01-01')))->toBeNull();
});

it('never expires a batch marked to keep', function () {
    expect(RetentionPolicy::expiresAt(new DateTimeImmutable('2026-01-01'), 30, true))->toBeNull();
});

it('counts the days left until the purge', function () {
    $deleted = new DateTimeImmutable('2026-01-01 10:00');

    expect(RetentionPolicy::expiresAt($deleted, 30, false)?->format('Y-m-d H:i'))->toBe('2026-01-31 10:00')
        ->and(RetentionPolicy::daysLeft($deleted, 30, false, new DateTimeImmutable('2026-01-25 09:00')))->toBe(7)
        ->and(RetentionPolicy::daysLeft($deleted, 30, false, new DateTimeImmutable('2026-01-31 09:59')))->toBe(1)
        ->and(RetentionPolicy::daysLeft($deleted, 30, false, new DateTimeImmutable('2026-02-05')))->toBe(0);
});

it('reads the retention from the environment value', function () {
    expect(RetentionPolicy::days(null))->toBe(0)
        ->and(RetentionPolicy::days(''))->toBe(0)
        ->and(RetentionPolicy::days('90'))->toBe(90)
        ->and(RetentionPolicy::days('abc'))->toBe(0)
        ->and(RetentionPolicy::days('-3'))->toBe(0);
});
