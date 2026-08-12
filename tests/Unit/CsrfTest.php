<?php

declare(strict_types=1);

use App\Support\Csrf;

beforeEach(function () {
    $_SESSION = [];
});

it('generates a token and reuses the same one on subsequent calls', function () {
    $first = Csrf::token();
    $second = Csrf::token();

    expect($first)->toBeString()->not->toBe('')
        ->and($second)->toBe($first);
});

it('generates tokens long enough to not be brute-forceable', function () {
    expect(strlen(Csrf::token()))->toBeGreaterThanOrEqual(32);
});

it('verifies successfully against the token actually in the session', function () {
    $token = Csrf::token();

    expect(Csrf::verify($token))->toBeTrue();
});

it('rejects a wrong or missing submitted token', function () {
    Csrf::token();

    expect(Csrf::verify('token-errado'))->toBeFalse()
        ->and(Csrf::verify(null))->toBeFalse()
        ->and(Csrf::verify(''))->toBeFalse();
});

it('rejects any token when there is no session token yet', function () {
    expect(Csrf::verify('qualquer-coisa'))->toBeFalse();
});

describe('matches() — comparação pura', function () {
    it('matches identical non-empty strings', function () {
        expect(Csrf::matches('abc123', 'abc123'))->toBeTrue();
    });

    it('rejects when either side is null, empty, or different', function () {
        expect(Csrf::matches('abc123', 'abc124'))->toBeFalse()
            ->and(Csrf::matches(null, 'abc123'))->toBeFalse()
            ->and(Csrf::matches('abc123', null))->toBeFalse()
            ->and(Csrf::matches('', 'abc123'))->toBeFalse()
            ->and(Csrf::matches('abc123', ''))->toBeFalse()
            ->and(Csrf::matches(null, null))->toBeFalse();
    });
});
