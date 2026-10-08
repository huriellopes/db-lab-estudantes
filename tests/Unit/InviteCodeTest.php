<?php

declare(strict_types=1);

use App\Support\InviteCode;

it('generates XXXX-XXXX codes without look-alike characters', function () {
    foreach (range(1, 200) as $_) {
        $code = InviteCode::generate();
        expect($code)->toMatch(InviteCode::PATTERN)
            ->and($code)->not->toMatch('/[01IO]/');
    }
});

it('normalizes what a student types', function () {
    expect(InviteCode::normalize(' abcd-ef23 '))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize('abcdef23'))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize('AB CD EF 23'))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize(''))->toBeNull()
        ->and(InviteCode::normalize('ABCD-EF2'))->toBeNull()
        ->and(InviteCode::normalize('ABCD-EF20'))->toBeNull()
        ->and(InviteCode::normalize("ABCD-EF23'; DROP"))->toBeNull();
});
