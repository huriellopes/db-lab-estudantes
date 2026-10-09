<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // Configurações que o admin muda pela tela (ver App\Models\AppSetting). No banco, e não em
        // storage/cache como o modo manutenção, porque precisam sobreviver a deploy/recriar container.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (
                name       VARCHAR(64) PRIMARY KEY,
                value      TEXT NULL,
                updated_by VARCHAR(100) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS app_settings');
    }
};
