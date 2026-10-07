<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDOException;

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
    /**
     * Conexões simultâneas por conta de aluno/professor. Cobre com folga o uso normal
     * (phpMyAdmin + console SQL + um SGBD local, que costuma abrir 2–3 conexões), mas impede
     * uma conta só, com a porta do MySQL pública, de esgotar o max_connections do servidor
     * inteiro — e com isso derrubar a app e a turma toda.
     */
    public const MAX_USER_CONNECTIONS = 10;

    public static function createMysqlAccount(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec(
            "CREATE USER IF NOT EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}"
            . ' WITH MAX_USER_CONNECTIONS ' . self::MAX_USER_CONNECTIONS,
        );

        // Escopo idêntico ao prefixo que a app já valida/usa pros schemas "oficiais" (ver
        // App\Support\SchemaNameBuilder) — deixa a própria conta MySQL do aluno rodar
        // CREATE DATABASE dentro do próprio namespace direto pelo console SQL (ver
        // SqlConsoleController), sem precisar passar pelo formulário. Fora desse prefixo a
        // conta continua sem NENHUM privilégio: não é uma conta mais poderosa, só move
        // onde o CREATE é concedido. ALL PRIVILEGES pra ficar idêntico ao que já é
        // concedido por schema em createDatabase() abaixo.
        $pdo->exec('GRANT ALL PRIVILEGES ON `' . self::ownDatabasesPattern($login) . "`.* TO '{$login}'@'%'");
    }

    /**
     * Pattern de database-level GRANT (com wildcard % no fim) escopado ao prefixo
     * "<login>__" — usado tanto na concessão quanto na revogação (rename). "_" é wildcard
     * de 1 caractere em pattern de GRANT mesmo dentro de crases, por isso escapamos todo
     * "_" literal do login como "\_": sem isso, o login "ana_costa" também bateria (por
     * coincidência de wildcard) com um database prefixado "anaXcosta__", furando o
     * isolamento entre contas.
     */
    private static function ownDatabasesPattern(string $login): string
    {
        return str_replace('_', '\\_', $login . '__') . '%';
    }

    public static function changeMysqlPassword(string $login, string $password): void
    {
        $pdo = Database::connection();
        $quotedPassword = $pdo->quote($password);

        $pdo->exec("ALTER USER IF EXISTS '{$login}'@'%' IDENTIFIED BY {$quotedPassword}");
    }

    /**
     * RENAME USER preserva todos os GRANTs existentes — os schemas já criados continuam
     * acessíveis. Mas o pattern com wildcard de ownDatabasesPattern() é só uma string pro
     * MySQL (ele não entende que era baseado no login antigo), então RENAME USER não a
     * atualiza sozinho: sem o REVOKE/GRANT abaixo, a pessoa perderia a permissão de criar
     * schema novo pelo console SQL com o login novo (o antigo pattern fica órfão, sem
     * efeito prático já que não há mais nenhum database com aquele prefixo criável).
     *
     * O REVOKE vai numa tentativa isolada porque REVOKE não tem "IF EXISTS" no MySQL:
     * contas criadas antes desse GRANT existir (ou que já passaram por outro rename) não
     * têm esse privilégio específico pra revogar, e isso não pode quebrar o rename inteiro.
     */
    public static function renameMysqlAccount(string $oldLogin, string $newLogin): void
    {
        $pdo = Database::connection();

        $pdo->exec("RENAME USER '{$oldLogin}'@'%' TO '{$newLogin}'@'%'");

        try {
            $pdo->exec('REVOKE ALL PRIVILEGES ON `' . self::ownDatabasesPattern($oldLogin) . "`.* FROM '{$newLogin}'@'%'");
        } catch (PDOException) {
            // Sem esse GRANT específico pra revogar — tudo bem, é só limpeza de um pattern órfão.
        }

        $pdo->exec('GRANT ALL PRIVILEGES ON `' . self::ownDatabasesPattern($newLogin) . "`.* TO '{$newLogin}'@'%'");
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
