<?php

declare(strict_types=1);

use App\Support\ClientIp;

it('uses REMOTE_ADDR when there is no X-Forwarded-For', function () {
    expect(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.4']))->toBe('203.0.113.4');
});

it('prefers the first IP in a X-Forwarded-For chain over REMOTE_ADDR', function () {
    expect(ClientIp::resolve([
        'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1, 10.0.0.2',
        'REMOTE_ADDR' => '10.0.0.2',
    ]))->toBe('198.51.100.7');
});

it('trims whitespace around the forwarded IP', function () {
    expect(ClientIp::resolve(['HTTP_X_FORWARDED_FOR' => '  198.51.100.7  ,10.0.0.1']))->toBe('198.51.100.7');
});

it('falls back to REMOTE_ADDR when X-Forwarded-For is empty or blank', function () {
    expect(ClientIp::resolve(['HTTP_X_FORWARDED_FOR' => '', 'REMOTE_ADDR' => '203.0.113.4']))->toBe('203.0.113.4')
        ->and(ClientIp::resolve(['HTTP_X_FORWARDED_FOR' => '   ', 'REMOTE_ADDR' => '203.0.113.4']))->toBe('203.0.113.4');
});

it('returns a placeholder when nothing is available at all', function () {
    expect(ClientIp::resolve([]))->toBe('0.0.0.0');
});
