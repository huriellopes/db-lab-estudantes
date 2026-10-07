<?php

declare(strict_types=1);

use App\Support\ClientIp;

it('uses REMOTE_ADDR', function () {
    expect(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.4']))->toBe('203.0.113.4');
});

it('ignores a forged X-Forwarded-For (the real IP comes from nginx real_ip, already in REMOTE_ADDR)', function () {
    // Antes o primeiro IP da cadeia vencia — e esse é justamente o que o cliente controla:
    // trocar o header a cada request zerava o rate limit de login/cadastro.
    expect(ClientIp::resolve([
        'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1',
        'REMOTE_ADDR' => '203.0.113.4',
    ]))->toBe('203.0.113.4');
});

it('returns a placeholder when REMOTE_ADDR is missing or blank', function () {
    expect(ClientIp::resolve([]))->toBe('0.0.0.0')
        ->and(ClientIp::resolve(['REMOTE_ADDR' => '']))->toBe('0.0.0.0');
});
