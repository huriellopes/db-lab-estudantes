<?php

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
