<?php

declare(strict_types=1);

use App\Core\Migration;
use App\Services\SchemaProvisioner;

/**
 * Backfill do MAX_USER_CONNECTIONS que SchemaProvisioner::createMysqlAccount() passou a
 * aplicar nas contas novas — sem isso, as contas que já existem continuariam sem limite
 * nenhum de conexões simultâneas. Idempotente.
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $this->applyLimit($pdo, SchemaProvisioner::MAX_USER_CONNECTIONS);
    }

    public function down(PDO $pdo): void
    {
        // 0 = sem limite (o padrão do MySQL).
        $this->applyLimit($pdo, 0);
    }

    private function applyLimit(PDO $pdo, int $limit): void
    {
        $stmt = $pdo->query("SELECT mysql_login FROM users WHERE mysql_login <> 'pending'");
        $logins = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($logins as $login) {
            $login = (string) $login;
            // Mesmo allow-list de App\Support\MysqlIdentifier (a coluna só é gravada por código
            // validado, mas o valor vai interpolado em DDL — não custa conferir de novo).
            if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $login)) {
                continue;
            }

            try {
                $pdo->exec("ALTER USER IF EXISTS '{$login}'@'%' WITH MAX_USER_CONNECTIONS {$limit}");
            } catch (PDOException $e) {
                error_log("Limite de conexões não aplicado pra '{$login}': {$e->getMessage()}");
            }
        }
    }
};
