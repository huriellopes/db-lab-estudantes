<?php

declare(strict_types=1);

namespace App\Actions\SqlConsole;

use App\Core\Action;
use App\Core\Auth;
use App\Core\Database;
use App\Models\Entities\Schema;
use App\Models\SchemaRecord;
use App\Support\AuthenticatedUser;
use App\Support\SchemaNameBuilder;
use App\Support\SqlScriptSplitter;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Console SQL do dashboard: roda os comandos que a pessoa digitar usando a conexão MySQL
 * REAL dela (App\Core\Database::connectAs), não a conexão admin da app — assim os GRANTs
 * que o MySQL já aplica por schema (ver App\Services\SchemaProvisioner::createDatabase)
 * barram sozinhos qualquer tentativa de mexer em schema de outra pessoa, sem precisar
 * reimplementar esse controle aqui.
 *
 * Sem schema selecionado (campo "schema" vazio) dá pra rodar comando sem `USE` nenhum —
 * inclusive `CREATE DATABASE <login>__algo;`, que funciona porque toda conta pessoal já
 * nasce com um GRANT com wildcard escopado ao próprio prefixo (ver
 * SchemaProvisioner::createMysqlAccount). Depois de qualquer execução, reconcileSchemas()
 * sincroniza `schemas_criados` com a realidade do MySQL, pra "Meus schemas" refletir um
 * CREATE/DROP DATABASE feito assim, por fora do formulário oficial. POST /dashboard/sql.
 */
final class RunSqlAction extends Action
{
    /** Corta o resultado exibido — evita travar o navegador com uma SELECT gigante. */
    private const MAX_ROWS = 300;

    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $script = (string) ($_POST['sql'] ?? '');
        $schema = trim((string) ($_POST['schema'] ?? ''));

        if (trim($script) === '') {
            $this->json(false, 'Digite algum comando SQL.');
        }

        $statements = SqlScriptSplitter::split($script);
        if ($statements === []) {
            $this->json(false, 'Nenhum comando válido encontrado (só comentários ou espaços em branco).');
        }

        $user = Auth::user();
        $password = Auth::mysqlPassword();
        if ($user === null || $password === null) {
            // needsMysqlPassword: true diz pro front-end (ver Alpine `sqlConsole` em
            // resources/js/app.js) pra abrir um campo de senha ali mesmo em vez de só
            // avisar "saia e entre de novo" — App\Actions\SqlConsole\ConfirmMysqlPasswordAction
            // recacheia a senha sem precisar de um logout/login completo (que perderia o
            // schema selecionado e faria a pessoa navegar pra longe do console à toa).
            $this->json(
                false,
                'Sua sessão não tem a senha MySQL em cache. Confirme sua senha abaixo pra continuar.',
                ['needsMysqlPassword' => true],
            );
        }

        try {
            $pdo = Database::connectAs($user->mysqlLogin, $password);
        } catch (PDOException $e) {
            $this->json(false, 'Não foi possível conectar no MySQL com sua conta: ' . $e->getMessage());
        }

        if ($schema !== '') {
            if (!SchemaNameBuilder::isValidDbName($schema)) {
                $this->json(false, 'Schema inválido.');
            }

            try {
                $pdo->exec("USE `{$schema}`");
            } catch (PDOException $e) {
                $this->json(false, "Não foi possível selecionar o schema \"{$schema}\": " . $e->getMessage());
            }
        }

        $results = [];
        $failure = null;
        foreach ($statements as $index => $statement) {
            try {
                $results[] = $this->runStatement($pdo, $statement);
            } catch (Throwable $e) {
                $failure = 'Erro no comando ' . ($index + 1) . ' de ' . count($statements) . ': ' . $e->getMessage();
                break;
            }
        }

        // Roda mesmo quando um comando falhou no meio (ex.: 2 comandos, o 1º era um
        // CREATE DATABASE que deu certo e o 2º falhou) — sem isso "Meus schemas" ficaria
        // desatualizado até a próxima ação que mexesse na tabela.
        $schemas = $this->reconcileSchemas($pdo, $user);

        if ($failure !== null) {
            $this->json(false, $failure, ['results' => $results, 'schemas' => $schemas]);
        }

        $this->json(true, count($statements) . ' comando(s) executado(s) com sucesso.', [
            'results' => $results,
            'schemas' => $schemas,
        ]);
    }

    /**
     * Sincroniza schemas_criados com o MySQL pro prefixo do usuário (ver
     * App\Models\SchemaRecord::reconcileForUser) e devolve a lista atualizada — o console
     * roda com a conexão pessoal da própria pessoa, então SHOW DATABASES já só devolve o
     * que ela mesma tem GRANT pra ver (nunca schema alheio nem `schoolapp`).
     *
     * @return list<string>
     */
    private function reconcileSchemas(PDO $pdo, AuthenticatedUser $user): array
    {
        $prefix = $user->mysqlLogin . '__';

        try {
            // Escapa "%"/"_" (wildcard de LIKE) do prefixo antes de acrescentar o "%" de
            // propósito no fim — sem isso um "_" no meio do login casaria com o prefixo
            // de outro login também (mesmo problema do GRANT, ver SchemaProvisioner).
            $likePattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
            $stmt = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($likePattern));
            $names = $stmt !== false ? array_map(static fn ($name): string => (string) $name, $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];

            $actual = array_values(array_filter(
                $names,
                static fn (string $name): bool => str_starts_with($name, $prefix) && SchemaNameBuilder::isValidDbName($name),
            ));

            SchemaRecord::reconcileForUser($user->id, $actual);

            return $actual;
        } catch (PDOException) {
            // Não deixa uma falha aqui (ex.: conexão caiu no meio) derrubar a resposta do
            // comando em si — só devolve a lista como já estava antes dessa execução.
            return array_map(static fn (Schema $schema): string => $schema->dbName, SchemaRecord::allForUser($user->id));
        }
    }

    /** @return array<string, mixed> */
    private function runStatement(PDO $pdo, string $statement): array
    {
        $stmt = $pdo->query($statement);

        if ($stmt === false) {
            throw new PDOException('Falha ao executar o comando.');
        }

        if ($stmt->columnCount() > 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'sql' => $statement,
                'type' => 'rows',
                'columns' => $this->columnNames($stmt),
                'rows' => array_slice($rows, 0, self::MAX_ROWS),
                'total' => count($rows),
                'truncated' => count($rows) > self::MAX_ROWS,
            ];
        }

        return [
            'sql' => $statement,
            'type' => 'write',
            'affected' => $stmt->rowCount(),
        ];
    }

    /** @return list<string> */
    private function columnNames(PDOStatement $stmt): array
    {
        $names = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $names[] = is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : "col{$i}";
        }

        return $names;
    }
}
