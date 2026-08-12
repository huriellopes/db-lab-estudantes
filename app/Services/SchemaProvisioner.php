<?php

namespace App\Services;

use App\Core\Database;

/**
 * Executa as operações administrativas no MySQL (criar/apagar contas e databases).
 *
 * IMPORTANTE: nenhum método aqui deve ser chamado dentro de uma transação PDO
 * (beginTransaction/commit). CREATE USER, CREATE DATABASE, DROP DATABASE, ALTER USER e
 * GRANT são comandos DDL e o MySQL faz commit implícito neles — misturar isso com
 * transação PDO deixa o estado da conexão fora de sincronia e o commit()/rollBack()
 * seguinte falha com "There is no active transaction", mesmo com a operação já persistida.
 */
class SchemaProvisioner
{
    public static function createMysqlAccount(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        // $login só contém [a-z0-9_], montado em App\Support\MysqlIdentifier — seguro
        // interpolar diretamente, já que identificadores não podem ser bind params.
        $pdo->exec("CREATE USER IF NOT EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
        $pdo->exec('FLUSH PRIVILEGES');
    }

    public static function changeMysqlPassword(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("ALTER USER IF EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
        $pdo->exec('FLUSH PRIVILEGES');
    }

    public static function dropMysqlAccount(string $login): void
    {
        Database::connection()->exec("DROP USER IF EXISTS '{$login}'@'%'");
    }

    public static function createDatabase(string $dbName, string $mysqlLogin): void
    {
        $pdo = Database::connection();

        // $dbName só contém [a-z0-9_], validado em App\Support\SchemaNameBuilder.
        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$mysqlLogin}'@'%'");
        $pdo->exec('FLUSH PRIVILEGES');
    }

    public static function dropDatabase(string $dbName): void
    {
        Database::connection()->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}
