<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\SshTunnel;

function tunnelEnv(array $overrides = []): array
{
    return array_merge([
        'SSH_TUNNEL_HOST' => '203.0.113.10',
        'SSH_TUNNEL_PORT' => '22',
        'SSH_TUNNEL_USER' => 'dblab-tunnel',
        'SSH_TUNNEL_PASSWORD' => 's3nh4-do-tunel',
        'MYSQL_PORT' => '13306',
    ], $overrides);
}

function renderConnectionPage(?SshTunnel $tunnel): string
{
    return View::render('connection/show', [
        'pageTitle' => 'Conectar via SGBD',
        'schemas' => [],
        'publicHost' => 'db.example.test',
        'externalPort' => '13306',
        'pmaUrl' => null,
        'tunnel' => $tunnel,
    ]);
}

it('is off when the tunnel user or host is not configured (local dev connects directly)', function () {
    expect(SshTunnel::fromEnv([]))->toBeNull()
        ->and(SshTunnel::fromEnv(tunnelEnv(['SSH_TUNNEL_USER' => ''])))->toBeNull()
        ->and(SshTunnel::fromEnv(tunnelEnv(['SSH_TUNNEL_HOST' => null])))->toBeNull();
});

it('reads the SSH side and the MySQL port bound to the server loopback', function () {
    $tunnel = SshTunnel::fromEnv(tunnelEnv());

    expect($tunnel->host)->toBe('203.0.113.10')
        ->and($tunnel->port)->toBe(22)
        ->and($tunnel->user)->toBe('dblab-tunnel')
        ->and($tunnel->password)->toBe('s3nh4-do-tunel')
        ->and($tunnel->dbHost)->toBe('127.0.0.1')
        ->and($tunnel->dbPort)->toBe(13306);
});

it('defaults to SSH port 22 and MySQL port 3306', function () {
    $tunnel = SshTunnel::fromEnv(tunnelEnv(['SSH_TUNNEL_PORT' => '', 'MYSQL_PORT' => null]));

    expect($tunnel->port)->toBe(22)->and($tunnel->dbPort)->toBe(3306);
});

it('builds the command-line forward, adding -p only for a non-default SSH port', function () {
    expect(SshTunnel::fromEnv(tunnelEnv())->forwardCommand())
        ->toBe('ssh -N -L 3307:127.0.0.1:13306 dblab-tunnel@203.0.113.10')
        ->and(SshTunnel::fromEnv(tunnelEnv(['SSH_TUNNEL_PORT' => '2222']))->forwardCommand())
        ->toBe('ssh -N -p 2222 -L 3307:127.0.0.1:13306 dblab-tunnel@203.0.113.10');
});

it('shows the SSH tunnel fields on the connection page when the tunnel is configured', function () {
    $html = renderConnectionPage(SshTunnel::fromEnv(tunnelEnv()));

    expect($html)
        ->toContain('203.0.113.10')
        ->toContain('dblab-tunnel')
        ->toContain('s3nh4-do-tunel')
        ->toContain('ssh -N -L 3307:127.0.0.1:13306 dblab-tunnel@203.0.113.10')
        ->toContain('mysql -h 127.0.0.1 -P 3307')
        ->not->toContain('sem túnel')
        // a porta do MySQL não é pública: a página não pode mandar conectar direto nela
        ->not->toContain('mysql -h db.example.test');
});

it('keeps the direct connection instructions when there is no tunnel (local dev)', function () {
    $html = renderConnectionPage(null);

    expect($html)
        ->toContain('mysql -h db.example.test -P 13306')
        ->not->toContain('ssh -N');
});
