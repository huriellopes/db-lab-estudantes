<?php

declare(strict_types=1);

use App\Models\Entities\PasswordResetToken;

it('is valid when not used and not expired', function () {
    $token = new PasswordResetToken(1, 1, new DateTimeImmutable('+1 hour'), null);

    expect($token->isValid(new DateTimeImmutable()))->toBeTrue()
        ->and($token->isUsed())->toBeFalse()
        ->and($token->isExpired(new DateTimeImmutable()))->toBeFalse();
});

it('is invalid once used, even before expiring', function () {
    $token = new PasswordResetToken(1, 1, new DateTimeImmutable('+1 hour'), new DateTimeImmutable());

    expect($token->isUsed())->toBeTrue()
        ->and($token->isValid(new DateTimeImmutable()))->toBeFalse();
});

it('is invalid once expired, even if never used', function () {
    $token = new PasswordResetToken(1, 1, new DateTimeImmutable('-1 minute'), null);

    expect($token->isExpired(new DateTimeImmutable()))->toBeTrue()
        ->and($token->isValid(new DateTimeImmutable()))->toBeFalse();
});

it('builds from a raw PDO row', function () {
    $token = PasswordResetToken::fromRow([
        'id' => '5',
        'user_id' => '9',
        'expires_at' => '2026-01-01 12:00:00',
        'used_at' => null,
    ]);

    expect($token->id)->toBe(5)
        ->and($token->userId)->toBe(9)
        ->and($token->usedAt)->toBeNull();
});
