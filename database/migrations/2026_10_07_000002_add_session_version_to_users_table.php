<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * Contador que invalida sessões já abertas (ver App\Core\Auth::enforceSession): a sessão
 * guarda o valor do login, e cada requisição compara com o do banco. Incrementado quando a
 * senha muda (pela própria pessoa, pelo "esqueci minha senha" ou por admin/professor), na
 * desativação e no soft delete — antes, nada disso derrubava quem já estava logado, nem
 * quem tinha roubado o cookie de sessão.
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER active');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP COLUMN session_version');
    }
};
