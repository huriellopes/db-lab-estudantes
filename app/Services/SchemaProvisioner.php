<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\SchemaNameBuilder;

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
    public static function createMysqlAccount(string $login, string $password, string $schemaPrefix): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("CREATE USER IF NOT EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");

        // Escopo idêntico ao prefixo (users.schema_prefix) que a app usa pros schemas
        // "oficiais" (ver App\Support\SchemaNameBuilder) — deixa a própria conta MySQL do
        // aluno rodar CREATE DATABASE dentro do próprio namespace direto pelo console SQL
        // (ver App\Actions\SqlConsole\RunSqlAction), sem passar pelo formulário. Fora desse
        // prefixo a conta continua sem NENHUM privilégio: não é uma conta mais poderosa, só move
        // onde o CREATE é concedido. ALL PRIVILEGES pra ficar idêntico ao que já é
        // concedido por schema em createDatabase() abaixo.
        $pdo->exec('GRANT ALL PRIVILEGES ON `' . SchemaNameBuilder::grantPattern($schemaPrefix) . "`.* TO '{$login}'@'%'");
    }

    public static function changeMysqlPassword(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("ALTER USER IF EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
    }

    /**
     * RENAME USER preserva todos os GRANTs existentes — inclusive o GRANT com wildcard do
     * prefixo de schema, que de propósito continua o mesmo: users.schema_prefix não muda no
     * rename (ver App\Support\SchemaNameBuilder::build). Antes o pattern era trocado pro
     * login novo, e o login antigo ficava livre pra outra pessoa cadastrar e herdar, pelo
     * mesmo pattern, os databases que continuavam com o nome antigo.
     */
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
