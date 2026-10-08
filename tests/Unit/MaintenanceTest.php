<?php

declare(strict_types=1);

use App\Services\BackupService;
use App\Services\HealthCheck;
use App\Services\Maintenance;
use App\Support\ErrorLogger;
use App\Support\HealthStatus;

beforeEach(function () {
    $this->storage = sys_get_temp_dir() . '/dblab-maint-' . bin2hex(random_bytes(4));
    foreach (['logs', 'backups', 'twig-cache', 'cache'] as $dir) {
        mkdir($this->storage . '/' . $dir, 0777, true);
    }
    Maintenance::useStorage($this->storage);
    ErrorLogger::useDirectory($this->storage . '/logs');
    BackupService::useDirectory($this->storage . '/backups');
});

afterEach(function () {
    Maintenance::useStorage(null);
    ErrorLogger::useDirectory(null);
    BackupService::useDirectory(null);
    exec('rm -rf ' . escapeshellarg($this->storage));
});

it('turns maintenance mode on and off with who/when/message', function () {
    expect(Maintenance::isOn())->toBeFalse()
        ->and(Maintenance::mode())->toBeNull();

    expect(Maintenance::enable('Huriel Lopes', '  Voltamos às 20h  '))->toBeTrue();

    $mode = Maintenance::mode();
    expect(Maintenance::isOn())->toBeTrue()
        ->and($mode['by'])->toBe('Huriel Lopes')
        ->and($mode['message'])->toBe('Voltamos às 20h')
        ->and($mode['since'])->not->toBe('');

    Maintenance::disable();
    expect(Maintenance::isOn())->toBeFalse();
});

it('lets only admins through maintenance mode, except login/logout/csrf', function () {
    expect(Maintenance::allows('/dashboard', false))->toBeFalse()
        ->and(Maintenance::allows('/admin', false))->toBeFalse()
        ->and(Maintenance::allows('/dashboard', true))->toBeTrue()
        ->and(Maintenance::allows('/login', false))->toBeTrue()
        ->and(Maintenance::allows('/login/', false))->toBeTrue()
        ->and(Maintenance::allows('/logout', false))->toBeTrue()
        ->and(Maintenance::allows('/csrf-token', false))->toBeTrue()
        ->and(Maintenance::allows('/loginx', false))->toBeFalse();
});

it('prunes logs older than N days but never today', function () {
    $now = new DateTimeImmutable('2026-10-08 12:00');
    foreach (['2026-10-08', '2026-10-06', '2026-10-01', '2026-09-20'] as $date) {
        file_put_contents($this->storage . "/logs/app-{$date}.log", str_repeat('x', 10));
    }

    expect(Maintenance::pruneLogs(3, $now))->toBe(['files' => 2, 'bytes' => 20])
        ->and(array_keys(ErrorLogger::files()))->toBe(['2026-10-08', '2026-10-06']);

    expect(Maintenance::pruneLogs(0, $now)['files'])->toBe(1)
        ->and(array_keys(ErrorLogger::files()))->toBe(['2026-10-08']);
});

it('keeps only the N most recent backups', function () {
    foreach (['a_20261001-100000', 'a_20261002-100000', 'a_20261003-100000'] as $i => $name) {
        $file = $this->storage . "/backups/{$name}.sql.gz";
        file_put_contents($file, str_repeat('y', 5));
        touch($file, 1_700_000_000 + $i);
    }

    expect(Maintenance::pruneBackups(1))->toBe(['files' => 2, 'bytes' => 10])
        ->and(array_column(BackupService::all(), 'name'))->toBe(['a_20261003-100000.sql.gz']);
});

it('empties the twig cache without following symlinks out of it', function () {
    mkdir($this->storage . '/twig-cache/ab', 0777, true);
    file_put_contents($this->storage . '/twig-cache/ab/x.php', '12345');
    $outside = $this->storage . '/cache/keep.txt';
    file_put_contents($outside, 'keep');
    symlink($this->storage . '/cache', $this->storage . '/twig-cache/link');

    $removed = Maintenance::clearTwigCache();

    expect($removed['bytes'])->toBe(5)
        ->and(glob($this->storage . '/twig-cache/*'))->toBe([])
        ->and(is_dir($this->storage . '/twig-cache'))->toBeTrue()
        ->and(file_get_contents($outside))->toBe('keep');
});

it('measures storage folders', function () {
    file_put_contents($this->storage . '/logs/app-2026-10-08.log', str_repeat('x', 100));
    mkdir($this->storage . '/twig-cache/cd');
    file_put_contents($this->storage . '/twig-cache/cd/y.php', str_repeat('x', 50));

    expect(Maintenance::storageUsage())->toBe(['logs' => 100, 'backups' => 0, 'twig-cache' => 50, 'cache' => 0]);
});

it('reads container memory and cpu limits from cgroup v2 files', function () {
    $cg = $this->storage . '/cgroup';
    mkdir($cg);
    file_put_contents($cg . '/memory.current', "1048576\n");
    file_put_contents($cg . '/memory.max', "536870912\n");
    file_put_contents($cg . '/cpu.max', "50000 100000\n");

    expect(Maintenance::containerMemory($cg))->toBe(['used' => 1_048_576, 'limit' => 536_870_912])
        ->and(Maintenance::containerCpuLimit($cg))->toBe(0.5);

    file_put_contents($cg . '/memory.max', "max\n");
    file_put_contents($cg . '/cpu.max', "max 100000\n");
    expect(Maintenance::containerMemory($cg)['limit'])->toBeNull()
        ->and(Maintenance::containerCpuLimit($cg))->toBeNull()
        ->and(Maintenance::containerMemory($this->storage . '/nope'))->toBeNull();
});

it('falls back to cgroup v1 files for memory and cpu limits', function () {
    $cg = $this->storage . '/cgroup1';
    mkdir($cg . '/memory', 0777, true);
    mkdir($cg . '/cpu', 0777, true);
    file_put_contents($cg . '/memory/memory.usage_in_bytes', "2097152\n");
    file_put_contents($cg . '/memory/memory.limit_in_bytes', "1073741824\n");
    file_put_contents($cg . '/cpu/cpu.cfs_quota_us', "200000\n");
    file_put_contents($cg . '/cpu/cpu.cfs_period_us', "100000\n");

    expect(Maintenance::containerMemory($cg))->toBe(['used' => 2_097_152, 'limit' => 1_073_741_824])
        ->and(Maintenance::containerCpuLimit($cg))->toBe(2.0);

    file_put_contents($cg . '/memory/memory.limit_in_bytes', "9223372036854771712\n");
    file_put_contents($cg . '/cpu/cpu.cfs_quota_us', "-1\n");
    expect(Maintenance::containerMemory($cg)['limit'])->toBeNull()
        ->and(Maintenance::containerCpuLimit($cg))->toBeNull();
});

it('describes the app health saying the free percentage is disk, with a hint when it fails', function () {
    $gb = 1024 ** 3;

    $ok = HealthCheck::describeApp('8.5.11', 12 * $gb, 40 * $gb, true);
    expect($ok->ok)->toBeTrue()
        ->and($ok->detail)->toBe('PHP 8.5.11 · disco: 12,0 GB livres de 40,0 GB (30%)')
        ->and($ok->hint)->toBe('');

    $full = HealthCheck::describeApp('8.5.11', 1 * $gb, 40 * $gb, true);
    expect($full->ok)->toBeFalse()
        ->and($full->detail)->toContain('menos de 5% livre')
        ->and($full->hint)->toContain('/admin/manutencao');

    $readonly = HealthCheck::describeApp('8.5.11', null, null, false);
    expect($readonly->ok)->toBeFalse()
        ->and($readonly->detail)->toBe('PHP 8.5.11 · storage/ sem permissão de escrita')
        ->and($readonly->hint)->toContain('chown');
});

it('keeps the hint through the health cache round-trip', function () {
    $status = new HealthStatus('phpMyAdmin', false, null, 'Sem resposta', 'docker compose restart phpmyadmin');

    expect(HealthStatus::fromArray($status->toArray()))->toEqual($status);
});
