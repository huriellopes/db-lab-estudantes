<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        // "Não apagar": o lote (vale a linha raiz) fica fora do expurgo automático por tempo
        // (ARCHIVE_RETENTION_DAYS, ver App\Services\Archiver::purgeExpired).
        $pdo->exec('ALTER TABLE deleted_models ADD COLUMN keep TINYINT(1) NOT NULL DEFAULT 0 AFTER meta');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE deleted_models DROP COLUMN keep');
    }
};
