<?php

declare(strict_types=1);

use App\Support\MysqlIdentifier;

it('builds a login from the id and the email local-part', function () {
    expect(MysqlIdentifier::build(7, 'joao.silva@example.com'))->toBe('u7_joaosilva');
});

it('strips accents-stripped non-alphanumeric characters from the email', function () {
    expect(MysqlIdentifier::build(1, 'ana+teste_2026@example.com'))->toBe('u1_anateste2026');
});

it('falls back to "user" when the local-part has no valid characters left', function () {
    expect(MysqlIdentifier::build(3, '___@example.com'))->toBe('u3_user');
});

it('never exceeds the 32 character MySQL username limit', function () {
    $login = MysqlIdentifier::build(999, str_repeat('a', 100) . '@example.com');

    expect(strlen($login))->toBeLessThanOrEqual(32)
        ->and($login)->toStartWith('u999_');
});

it('always starts with u<id>_', function () {
    expect(MysqlIdentifier::build(42, 'x@example.com'))->toStartWith('u42_');
});

it('accepts a well-formed custom login (letter first, then letters/digits/underscore)', function () {
    expect(MysqlIdentifier::isValidCustomLogin('joao_silva'))->toBeTrue()
        ->and(MysqlIdentifier::isValidCustomLogin('a1b'))->toBeTrue();
});

it('rejects a custom login starting with a digit or underscore', function () {
    expect(MysqlIdentifier::isValidCustomLogin('1joao'))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin('_joao'))->toBeFalse();
});

it('rejects a custom login with uppercase letters or symbols that could break out of the identifier', function () {
    expect(MysqlIdentifier::isValidCustomLogin('Joao'))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin("joao'; DROP TABLE users; --"))->toBeFalse();
});

it('rejects a custom login outside the 3-32 character range', function () {
    expect(MysqlIdentifier::isValidCustomLogin('ab'))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin(str_repeat('a', 33)))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin(str_repeat('a', 32)))->toBeTrue();
});

it('rejects reserved system/app account names', function () {
    expect(MysqlIdentifier::isValidCustomLogin('root'))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin('appuser'))->toBeFalse()
        ->and(MysqlIdentifier::isValidCustomLogin('admin'))->toBeFalse();
});
