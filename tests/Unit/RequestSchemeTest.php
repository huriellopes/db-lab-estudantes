<?php

declare(strict_types=1);

use App\Support\RequestScheme;

it('is false when nothing indicates https', function () {
    expect(RequestScheme::isHttps([]))->toBeFalse();
});

it('trusts X-Forwarded-Proto: https from the reverse proxy', function () {
    expect(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']))->toBeTrue();
});

it('is case-insensitive and trims whitespace on X-Forwarded-Proto', function () {
    expect(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => ' HTTPS ']))->toBeTrue();
});

it('takes only the first value when X-Forwarded-Proto has a comma-separated chain', function () {
    expect(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https,http']))->toBeTrue()
        ->and(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'http,https']))->toBeFalse();
});

it('is false when X-Forwarded-Proto is http', function () {
    expect(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'http']))->toBeFalse();
});

it('falls back to the native $_SERVER[HTTPS] convention', function () {
    expect(RequestScheme::isHttps(['HTTPS' => 'on']))->toBeTrue()
        ->and(RequestScheme::isHttps(['HTTPS' => '1']))->toBeTrue()
        ->and(RequestScheme::isHttps(['HTTPS' => 'off']))->toBeFalse()
        ->and(RequestScheme::isHttps(['HTTPS' => '']))->toBeFalse();
});
