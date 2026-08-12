<?php

declare(strict_types=1);

use App\Support\ProfileFields;

it('accepts a name within the valid length range', function () {
    expect(ProfileFields::isValidName('Ana'))->toBeTrue()
        ->and(ProfileFields::isValidName(str_repeat('a', 100)))->toBeTrue();
});

it('rejects a name shorter than 2 characters', function () {
    expect(ProfileFields::isValidName(''))->toBeFalse()
        ->and(ProfileFields::isValidName('A'))->toBeFalse();
});

it('rejects a name longer than the users.name column (100 chars)', function () {
    expect(ProfileFields::isValidName(str_repeat('a', 101)))->toBeFalse();
});

it('counts multi-byte characters correctly, not raw bytes', function () {
    // "á" ocupa 2 bytes em UTF-8 — 100 desses caberia em 200 bytes, mas ainda são só
    // 100 caracteres, então deve ser aceito (mb_strlen, não strlen).
    expect(ProfileFields::isValidName(str_repeat('á', 100)))->toBeTrue()
        ->and(ProfileFields::isValidName(str_repeat('á', 101)))->toBeFalse();
});

it('accepts a well-formed email within the length limit', function () {
    expect(ProfileFields::isValidEmail('fulano@example.com'))->toBeTrue();
});

it('rejects a malformed email', function () {
    expect(ProfileFields::isValidEmail('nao-e-email'))->toBeFalse()
        ->and(ProfileFields::isValidEmail(''))->toBeFalse();
});

it('rejects an email longer than the users.email column (150 chars)', function () {
    $tooLong = str_repeat('a', 145) . '@example.com';

    expect(strlen($tooLong))->toBeGreaterThan(150)
        ->and(ProfileFields::isValidEmail($tooLong))->toBeFalse();
});
