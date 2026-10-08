<?php

declare(strict_types=1);

use App\Actions\Admin\ToggleMaintenanceModeAction;
use App\Services\LogTail;
use App\Support\ErrorLogger;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/dblab-tail-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
    ErrorLogger::useDirectory($this->dir);
    $this->file = $this->dir . '/app-2026-10-08.log';
    $this->write = function (string $message, string $level = 'error'): void {
        file_put_contents($this->file, json_encode(['time' => '2026-10-08T10:00:00-03:00', 'level' => $level, 'message' => $message, 'route' => 'GET /x']) . "\n", FILE_APPEND);
    };
});

afterEach(function () {
    ErrorLogger::useDirectory(null);
    exec('rm -rf ' . escapeshellarg($this->dir));
});

it('returns an empty tail and a cursor at the end when there is no log yet', function () {
    expect(LogTail::app('', '2026-10-08'))->toBe(['entries' => [], 'cursor' => '2026-10-08:0']);
});

it('loads the last lines first and then only what was appended after the cursor', function () {
    foreach (range(1, LogTail::BACKLOG + 20) as $i) {
        ($this->write)("erro {$i}");
    }

    $first = LogTail::app('', '2026-10-08');
    expect($first['entries'])->toHaveCount(LogTail::BACKLOG)
        ->and($first['entries'][0]['message'])->toBe('erro 21')
        ->and(end($first['entries'])['message'])->toBe('erro ' . (LogTail::BACKLOG + 20))
        ->and($first['entries'][0]['detail'])->toContain('GET /x');

    expect(LogTail::app($first['cursor'], '2026-10-08')['entries'])->toBe([]);

    ($this->write)('novo', 'warning');
    $next = LogTail::app($first['cursor'], '2026-10-08');
    expect(array_column($next['entries'], 'message'))->toBe(['novo'])
        ->and($next['entries'][0]['level'])->toBe('warning');
});

it('leaves a half-written line for the next read', function () {
    ($this->write)('completa');
    $cursor = LogTail::app('', '2026-10-08')['cursor'];

    file_put_contents($this->file, '{"time":"x","level":"error","mess', FILE_APPEND);
    $partial = LogTail::app($cursor, '2026-10-08');
    expect($partial['entries'])->toBe([])->and($partial['cursor'])->toBe($cursor);

    file_put_contents($this->file, "age\":\"terminou\"}\n", FILE_APPEND);
    expect(array_column(LogTail::app($cursor, '2026-10-08')['entries'], 'message'))->toBe(['terminou']);
});

it('starts over when the day changes or the file shrank', function () {
    ($this->write)('hoje');

    expect(array_column(LogTail::app('2026-10-07:999', '2026-10-08')['entries'], 'message'))->toBe(['hoje'])
        ->and(array_column(LogTail::app('2026-10-08:99999', '2026-10-08')['entries'], 'message'))->toBe(['hoje']);
});

it('maps MySQL error log priorities to the app levels', function () {
    expect(LogTail::mysqlLevel('Error'))->toBe('error')
        ->and(LogTail::mysqlLevel('Warning'))->toBe('warning')
        ->and(LogTail::mysqlLevel('System'))->toBe('notice')
        ->and(LogTail::mysqlLevel('Note'))->toBe('info');
});

it('only redirects back to a local path after turning maintenance off', function () {
    expect(ToggleMaintenanceModeAction::backPath('http://localhost:8080/admin/usuarios?page=2'))->toBe('/admin/usuarios?page=2')
        ->and(ToggleMaintenanceModeAction::backPath('https://evil.example/phish'))->toBe('/phish')
        ->and(ToggleMaintenanceModeAction::backPath('//evil.example/x'))->toBe('/x')
        ->and(ToggleMaintenanceModeAction::backPath('http://localhost//evil.example'))->toBe('/admin/manutencao')
        ->and(ToggleMaintenanceModeAction::backPath(null))->toBe('/admin/manutencao')
        ->and(ToggleMaintenanceModeAction::backPath('javascript:alert(1)'))->toBe('/admin/manutencao');
});
