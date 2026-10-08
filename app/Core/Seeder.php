<?php

declare(strict_types=1);

namespace App\Core;

abstract class Seeder
{
    abstract public function run(): void;

    /** Roda outro seeder — igual ao `$this->call()` do Laravel. */
    protected function call(string $seeder): void
    {
        (new $seeder())->run();
    }

    protected function info(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}
