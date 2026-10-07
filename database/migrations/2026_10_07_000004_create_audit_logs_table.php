<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- Sem FK de propósito: o registro de auditoria tem que sobreviver à exclusão
                -- definitiva da conta que fez (ou sofreu) a ação. user_name guarda o nome da
                -- época pelo mesmo motivo.
                user_id     INT NULL,
                user_name   VARCHAR(100) NULL,
                action      VARCHAR(64) NOT NULL,
                target_type VARCHAR(32) NULL,
                target_id   VARCHAR(64) NULL,
                meta        JSON NULL,
                ip          VARCHAR(45) NOT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at),
                INDEX idx_user (user_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
    }
};
