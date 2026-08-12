<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Executa as operações administrativas no MySQL (criar/apagar contas e databases).
 *
 * IMPORTANTE: nenhum método aqui deve ser chamado dentro de uma transação PDO
 * (beginTransaction/commit). CREATE USER, CREATE DATABASE, DROP DATABASE, ALTER/RENAME
 * USER e GRANT são comandos DDL e o MySQL faz commit implícito neles — misturar isso com
 * transação PDO deixa o estado da conexão fora de sincronia e o commit()/rollBack()
 * seguinte falha com "There is no active transaction", mesmo com a operação já persistida.
 *
 * Sem FLUSH PRIVILEGES depois de CREATE/ALTER/RENAME USER e GRANT: esses comandos já
 * atualizam o cache de privilégios em memória sozinhos (confirmado na prática — um usuário
 * criado agora mesmo já consegue logar e usar o GRANT imediatamente, sem flush nenhum).
 * FLUSH PRIVILEGES só é necessário quando alguém edita as tabelas mysql.* na mão via
 * INSERT/UPDATE direto, o que a app nunca faz. Removido de propósito: exigia o privilégio
 * RELOAD pro appuser sem necessidade real (ver SECURITY.md).
 *
 * Todos os parâmetros que viram identificador (login, dbName) DEVEM ter sido validados
 * contra uma allow-list de caracteres por quem chama (ver App\Support\MysqlIdentifier e
 * App\Support\SchemaNameBuilder) — aqui eles são interpolados diretamente, porque o MySQL
 * não aceita identificador como bind parameter.
 */
final class SchemaProvisioner
{
    public static function createMysqlAccount(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("CREATE USER IF NOT EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
    }

    public static function changeMysqlPassword(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("ALTER USER IF EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
    }

    /** RENAME USER preserva todos os GRANTs existentes — os schemas continuam acessíveis. */
    public static function renameMysqlAccount(string $oldLogin, string $newLogin): void
    {
        Database::connection()->exec("RENAME USER '{$oldLogin}'@'%' TO '{$newLogin}'@'%'");
    }

    /** Bloqueia o login (a conta e os databases continuam intactos) — usado em desativar/soft delete. */
    public static function lockMysqlAccount(string $login): void
    {
        Database::connection()->exec("ALTER USER IF EXISTS '{$login}'@'%' ACCOUNT LOCK");
    }

    public static function unlockMysqlAccount(string $login): void
    {
        Database::connection()->exec("ALTER USER IF EXISTS '{$login}'@'%' ACCOUNT UNLOCK");
    }

    public static function dropMysqlAccount(string $login): void
    {
        Database::connection()->exec("DROP USER IF EXISTS '{$login}'@'%'");
    }

    public static function createDatabase(string $dbName, string $mysqlLogin): void
    {
        $pdo = Database::connection();

        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$mysqlLogin}'@'%'");
    }

    public static function dropDatabase(string $dbName): void
    {
        Database::connection()->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}
