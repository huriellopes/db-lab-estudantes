<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // Quem estava por trás quando a ação foi feita durante um "entrar como" (ver
        // App\Core\Auth::impersonate): user_* é a conta impersonada, impersonator_* o admin.
        // Sem FK pelo mesmo motivo de user_id: a auditoria sobrevive à exclusão da conta.
        $pdo->exec(
            'ALTER TABLE audit_logs
                ADD COLUMN impersonator_id INT NULL AFTER user_name,
                ADD COLUMN impersonator_name VARCHAR(100) NULL AFTER impersonator_id,
                ADD INDEX idx_impersonator (impersonator_id)'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE audit_logs DROP INDEX idx_impersonator, DROP COLUMN impersonator_name, DROP COLUMN impersonator_id');
    }
};
