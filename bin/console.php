#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Console;
use App\Core\Database;
use App\Core\Migrator;
use Dotenv\Dotenv;

// Só é usado fora do Docker, já que em produção/dev com docker-compose as variáveis já
// chegam via `environment:`.
if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$console = new Console(new Migrator(Database::connection()));

exit($console->run($argv));
