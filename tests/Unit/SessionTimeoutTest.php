<?php

declare(strict_types=1);

use App\Support\SessionTimeout;

const NOW = 1_800_000_000;

it('keeps a session that is active and recent', function () {
    expect(SessionTimeout::isExpired(NOW, lastActivityAt: NOW - 60, loggedInAt: NOW - 3600))->toBeFalse();
});

it('expires a session idle for longer than the idle limit', function () {
    expect(SessionTimeout::isExpired(NOW, lastActivityAt: NOW - SessionTimeout::IDLE_SECONDS - 1, loggedInAt: NOW - 3 * 3600))
        ->toBeTrue();
});

it('keeps a session idle for exactly the idle limit', function () {
    expect(SessionTimeout::isExpired(NOW, lastActivityAt: NOW - SessionTimeout::IDLE_SECONDS, loggedInAt: NOW - 3 * 3600))
        ->toBeFalse();
});

it('expires a session older than the absolute limit even if it is active', function () {
    expect(SessionTimeout::isExpired(NOW, lastActivityAt: NOW - 5, loggedInAt: NOW - SessionTimeout::ABSOLUTE_SECONDS - 1))
        ->toBeTrue();
});

it('does not expire a session opened before the timestamps existed (nothing to compare against)', function () {
    // Sessões abertas antes do deploy não têm os timestamps — ganham agora como ponto de partida.
    expect(SessionTimeout::isExpired(NOW, lastActivityAt: null, loggedInAt: null))->toBeFalse();
});
