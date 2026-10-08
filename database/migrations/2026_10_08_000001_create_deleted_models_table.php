<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS deleted_models (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- UUID do lote: o que foi excluído junto (usuário + schemas + consultas + diagramas)
                -- é restaurado ou excluído definitivamente junto. Ver App\Services\Archiver.
                batch_id        CHAR(36) NOT NULL,
                -- 1 = item que a pessoa mandou excluir; os demais vieram junto por dependência.
                is_root         TINYINT(1) NOT NULL,
                model           VARCHAR(32) NOT NULL,
                -- id original: a restauração reinsere com o mesmo id.
                model_id        INT NOT NULL,
                label           VARCHAR(200) NOT NULL,
                -- Linha completa no momento da exclusão (inclusive password_hash: sem ele o
                -- usuário restaurado não teria senha). A tela mascara campos sensíveis.
                `values`        JSON NOT NULL,
                -- Ex.: database de quarentena, definições de objetos programáveis, removed_outside.
                meta            JSON NULL,
                -- Sem FK de propósito (igual audit_logs): o autor pode ser excluído depois.
                deleted_by_id   INT NULL,
                deleted_by_name VARCHAR(100) NULL,
                deleted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_batch (batch_id),
                INDEX idx_model (model, model_id),
                INDEX idx_deleted_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS deleted_models');
    }
};
