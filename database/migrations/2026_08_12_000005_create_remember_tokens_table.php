<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS remember_tokens (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                -- Só o hash (sha256) fica guardado, mesmo padrão de password_reset_tokens —
                -- o token em texto puro só existe no cookie do navegador da pessoa. Se o
                -- banco vazar, os cookies não servem pra nada sozinhos.
                token_hash  CHAR(64) NOT NULL UNIQUE,
                expires_at  TIMESTAMP NOT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- Sem limite de 1 por usuário (ao contrário do reset de senha): cada
                -- "lembrar de mim" é por dispositivo/navegador, então uma pessoa pode ter
                -- vários tokens válidos ao mesmo tempo (celular, notebook do trabalho...).
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS remember_tokens');
    }
};
