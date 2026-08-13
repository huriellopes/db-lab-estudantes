<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS saved_queries (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                title       VARCHAR(80) NOT NULL,
                -- Schema selecionado no console na hora de salvar — só um lembrete pra
                -- carregar de volta, não uma referência de verdade: continua funcionando
                -- (com schema "— nenhum —") mesmo que esse database seja apagado depois.
                schema_name VARCHAR(64) NULL,
                sql_text    TEXT NOT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_saved_queries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS saved_queries');
    }
};
