<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit_hits (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                -- Combina escopo + chave, ex.: "login:ip:203.0.113.4" ou
                -- "login:id:fulano@example.com" — ver App\Services\RateLimiter.
                bucket_key  VARCHAR(191) NOT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bucket_time (bucket_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS rate_limit_hits');
    }
};
