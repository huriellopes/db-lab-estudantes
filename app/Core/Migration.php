<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

abstract class Migration
{
    abstract public function up(PDO $pdo): void;

    abstract public function down(PDO $pdo): void;
}
