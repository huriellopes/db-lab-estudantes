<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // XXXX-XXXX (App\Support\InviteCode), único entre turmas; NULL = entrada por código
        // desativada. Turmas que já existem começam sem código: o responsável gera na tela.
        $pdo->exec('ALTER TABLE classes ADD COLUMN invite_code VARCHAR(9) NULL UNIQUE AFTER name');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE classes DROP COLUMN invite_code');
    }
};
