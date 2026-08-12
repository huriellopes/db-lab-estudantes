<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER active');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP COLUMN last_login_at');
    }
};
