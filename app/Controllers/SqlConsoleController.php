<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
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
 */
final class SqlConsoleController extends Controller
{
    /** Corta o resultado exibido — evita travar o navegador com uma SELECT gigante. */
    private const MAX_ROWS = 300;

    public function run(array $params = []): void
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
            $this->json(false, 'Sua sessão não tem a senha MySQL em cache — saia e entre de novo pra usar o console SQL.');
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
        foreach ($statements as $index => $statement) {
            try {
                $results[] = $this->runStatement($pdo, $statement);
            } catch (Throwable $e) {
                $this->json(false, 'Erro no comando ' . ($index + 1) . ' de ' . count($statements) . ': ' . $e->getMessage(), [
                    'results' => $results,
                ]);
            }
        }

        $this->json(true, count($statements) . ' comando(s) executado(s) com sucesso.', ['results' => $results]);
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

    /** @param array<string, mixed> $extra */
    private function json(bool $success, string $message, array $extra = []): never
    {
        http_response_code($success ? 200 : 422);
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message] + $extra);
        exit;
    }
}
