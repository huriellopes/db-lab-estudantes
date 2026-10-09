<?php

declare(strict_types=1);

use App\Core\ResilientTwigCache;
use App\Support\ErrorLogger;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/dblab-twigcache-' . bin2hex(random_bytes(4));
    mkdir($this->dir . '/cache', 0777, true);
    mkdir($this->dir . '/logs');
    ErrorLogger::useDirectory($this->dir . '/logs');
});

afterEach(function () {
    ErrorLogger::useDirectory(null);
    exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
});

it('still renders when the cache directory is not writable, logging a warning', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root escreve mesmo sem permissão');
    }

    // Mesmo cenário da produção: o subdiretório do hash existe, mas o PHP não escreve nele.
    $cache = new ResilientTwigCache($this->dir . '/cache');
    $twig = new Environment(new ArrayLoader(['page.twig' => 'Olá, {{ nome }}']), ['cache' => $cache, 'auto_reload' => true]);
    mkdir(dirname($cache->generateKey('page.twig', $twig->getTemplateClass('page.twig'))), 0555, true);

    expect($twig->render('page.twig', ['nome' => 'aluno']))->toBe('Olá, aluno');

    $entries = ErrorLogger::entriesFor(date('Y-m-d'));
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['message'])->toContain('Unable to write in the cache directory');
});

it('writes compiled templates normally when it can', function () {
    $cache = new ResilientTwigCache($this->dir . '/cache');
    $twig = new Environment(new ArrayLoader(['page.twig' => 'oi']), ['cache' => $cache]);

    expect($twig->render('page.twig'))->toBe('oi')
        ->and(glob($this->dir . '/cache/*/*.php'))->toHaveCount(1)
        ->and(ErrorLogger::entriesFor(date('Y-m-d')))->toBe([]);
});
