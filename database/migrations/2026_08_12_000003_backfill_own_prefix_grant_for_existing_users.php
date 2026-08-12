<?php

declare(strict_types=1);

use App\Core\Migration;

/**
 * Backfill do GRANT com wildcard adicionado em SchemaProvisioner::createMysqlAccount()
 * (ver PR #21, "Console SQL sem schema selecionado") — só passou a rodar para contas
 * NOVAS a partir dali. Quem já tinha conta MySQL antes disso nunca ganhou esse privilégio
 * (só tem GRANT nos schemas que já existiam quando essa migration roda), então continua
 * levando "Access denied" ao tentar `CREATE DATABASE <login>__algo` pelo console SQL sem
 * passar pelo formulário "Criar novo schema". Aqui replica o mesmo GRANT pra todo
 * mysql_login já cadastrado — idempotente (rodar de novo não faz mal, GRANT repete só
 * confirma o mesmo privilégio).
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT mysql_login FROM users WHERE mysql_login <> 'pending'");
        $logins = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($logins as $login) {
            $login = (string) $login;
            if ($login === '') {
                continue;
            }

            // Mesmo escape de SchemaProvisioner::ownDatabasesPattern() — "_" é wildcard de
            // 1 caractere em pattern de GRANT mesmo dentro de crases, escapado pra não
            // colidir por coincidência com o prefixo de outro login.
            $pattern = str_replace('_', '\\_', $login . '__') . '%';

            try {
                $pdo->exec("GRANT ALL PRIVILEGES ON `{$pattern}`.* TO '{$login}'@'%'");
            } catch (PDOException $e) {
                // Login sem conta MySQL correspondente (ex.: cadastro que falhou no meio e
                // não foi limpo direito) — não pode travar o backfill dos outros usuários.
                error_log("Backfill do GRANT com wildcard falhou pra '{$login}': {$e->getMessage()}");
            }
        }
    }

    public function down(PDO $pdo): void
    {
        // Não reversível com segurança: desfazer exigiria REVOKE de um pattern por login,
        // e REVOKE de um GRANT que não existe (login criado depois dessa migration, já
        // nasceu com o privilégio via createMysqlAccount()) dá erro. Sem efeito de propósito.
    }
};
