<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                -- Só o hash (sha256) fica guardado — o token em texto puro só existe no
                -- e-mail enviado. Se o banco vazar, os links não servem pra nada sozinhos.
                token_hash  CHAR(64) NOT NULL UNIQUE,
                expires_at  TIMESTAMP NOT NULL,
                used_at     TIMESTAMP NULL DEFAULT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS password_reset_tokens');
    }
};
