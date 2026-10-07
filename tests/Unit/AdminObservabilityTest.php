<?php

declare(strict_types=1);

use App\Services\BackupService;
use App\Services\HealthCheck;
use App\Support\AuditMeta;
use App\Support\ByteSize;
use App\Support\ErrorLogger;
use App\Support\HealthStatus;

it('round-trips a HealthStatus through the cache array format', function () {
    $status = new HealthStatus('MySQL', true, 12, 'MySQL 8');

    expect(HealthStatus::fromArray($status->toArray()))->toEqual($status);
});

it('formats durations for the health detail', function () {
    expect(HealthCheck::humanDuration(59))->toBe('0min')
        ->and(HealthCheck::humanDuration(3_660))->toBe('1h 1min')
        ->and(HealthCheck::humanDuration(90_000))->toBe('1d 1h');
});

it('formats byte sizes', function () {
    expect(ByteSize::format(512))->toBe('512 B')
        ->and(ByteSize::format(1_536))->toBe('1,5 KB')
        ->and(ByteSize::format(5 * 1024 * 1024))->toBe('5,0 MB');
});

it('never stores secrets in audit meta and truncates long values', function () {
    $clean = AuditMeta::sanitize([
        'password' => 'hunter2',
        'nova_senha' => 'x',
        'remember_token' => 'abc',
        'email' => 'a@b.c',
        'sql' => str_repeat('a', 500),
        'lista' => ['x' => 1],
    ]);

    expect($clean['password'])->toBe('[omitido]')
        ->and($clean['nova_senha'])->toBe('[omitido]')
        ->and($clean['remember_token'])->toBe('[omitido]')
        ->and($clean['email'])->toBe('a@b.c')
        ->and(mb_strlen($clean['sql']))->toBe(201)
        ->and($clean['lista'])->toBe('{"x":1}');
});

it('only accepts backup file names it generates (no path traversal)', function (string $name, bool $valid) {
    expect(BackupService::isValidFilename($name))->toBe($valid);
})->with([
    ['schoolapp_20261007-120000.sql.gz', true],
    ['joao__loja_20261007-120000.sql.gz', true],
    ['../../.env', false],
    ['schoolapp_20261007-120000.sql.gz/../x', false],
    ['schoolapp.sql', false],
    ['', false],
]);

describe('ErrorLogger', function () {
    beforeEach(function () {
        $this->dir = sys_get_temp_dir() . '/dblab-logs-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        ErrorLogger::useDirectory($this->dir);
    });

    afterEach(function () {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        ErrorLogger::useDirectory(null);
    });

    it('builds entries without the query string (may carry reset tokens)', function () {
        $entry = ErrorLogger::buildEntry('error', 'boom', 'x.php:1', 'trace', [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/redefinir-senha/abc?token=secret',
        ], 7);

        expect($entry['route'])->toBe('GET /redefinir-senha/abc')
            ->and($entry['user_id'])->toBe(7)
            ->and($entry['level'])->toBe('error');
    });

    it('writes, reads back newest first and groups recurring messages', function () {
        ErrorLogger::message('error', 'falhou A', 'a.php:1');
        ErrorLogger::message('error', 'falhou A', 'a.php:1');
        ErrorLogger::message('warning', 'aviso B', 'b.php:2');

        $entries = ErrorLogger::entriesFor(date('Y-m-d'));
        $groups = ErrorLogger::group($entries);

        expect($entries)->toHaveCount(3)
            ->and($entries[0]['message'])->toBe('aviso B')
            ->and($groups[0])->toMatchArray(['message' => 'falhou A', 'count' => 2])
            ->and(ErrorLogger::entriesFor(date('Y-m-d'), 'warning'))->toHaveCount(1)
            ->and(ErrorLogger::countSince(new DateTimeImmutable('-1 hour')))->toBe(3);
    });

    it('prunes files older than the retention window', function () {
        $old = (new DateTimeImmutable('-' . (ErrorLogger::RETENTION_DAYS + 1) . ' days'))->format('Y-m-d');
        file_put_contents($this->dir . "/app-{$old}.log", "{}\n");
        file_put_contents($this->dir . '/app-' . date('Y-m-d') . '.log', "{}\n");

        ErrorLogger::prune();

        expect(array_keys(ErrorLogger::files()))->toBe([date('Y-m-d')]);
    });
});
