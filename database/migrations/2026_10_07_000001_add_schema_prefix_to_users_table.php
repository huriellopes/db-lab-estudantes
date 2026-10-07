<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * Separa o prefixo dos schemas (users.schema_prefix) do login MySQL (users.mysql_login).
 *
 * Antes, o prefixo era o próprio login atual: quem renomeava o login de "ana" pra "bob"
 * continuava dono de "ana__loja" (o database não é renomeado), mas o login "ana" ficava
 * livre — e quem se cadastrasse como "ana" ganhava, via GRANT com wildcard `ana\_\_%` (ver
 * App\Services\SchemaProvisioner::createMysqlAccount), ALL PRIVILEGES nos databases antigos
 * da outra pessoa. Agora o prefixo é fixado uma vez, na criação da conta, e o login livre
 * passa a ser checado contra prefixos e schemas existentes (ver App\Models\User::isLoginTaken).
 *
 * Backfill: prefixo = login atual, que é exatamente o pattern de GRANT que cada conta já tem
 * hoje (renameMysqlAccount regravava o pattern com o login novo) — nenhum GRANT muda aqui.
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN schema_prefix VARCHAR(32) NULL DEFAULT NULL AFTER mysql_login');
        $pdo->exec("UPDATE users SET schema_prefix = mysql_login WHERE mysql_login <> 'pending'");
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY users_schema_prefix_unique (schema_prefix)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP INDEX users_schema_prefix_unique');
        $pdo->exec('ALTER TABLE users DROP COLUMN schema_prefix');
    }
};
