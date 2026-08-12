<?php

declare(strict_types=1);

namespace App\Core;

abstract class Seeder
{
    abstract public function run(): void;
}
