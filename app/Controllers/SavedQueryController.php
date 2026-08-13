<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Entities\SavedQuery as SavedQueryEntity;
use App\Models\SavedQuery;
use App\Support\SchemaNameBuilder;
use Throwable;

/**
 * Biblioteca pessoal de comandos SQL salvos a partir do console do dashboard (ver
 * Alpine `sqlConsole` em resources/js/app.js) — pensada pra quem quer guardar uma consulta
 * útil (ou um script de criação de tabelas) sem depender de copiar/colar em outro lugar,
 * e sem perder o que digitou se a sessão cair no meio (ver comentário em App\Core\Auth::requireLogin).
 */
final class SavedQueryController extends Controller
{
    private const MAX_TITLE_LENGTH = 80;

    public function store(array $params = []): void
    {
        Auth::requireLogin();

        $title = trim((string) ($_POST['title'] ?? ''));
        $sql = (string) ($_POST['sql'] ?? '');
        $schema = trim((string) ($_POST['schema'] ?? ''));

        if ($title === '') {
            $this->json(false, 'Dê um nome pra essa consulta antes de salvar.');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $this->json(false, 'Nome muito longo (máximo de ' . self::MAX_TITLE_LENGTH . ' caracteres).');
        }

        if (trim($sql) === '') {
            $this->json(false, 'Não há nenhum comando SQL pra salvar.');
        }

        if ($schema !== '' && !SchemaNameBuilder::isValidDbName($schema)) {
            $this->json(false, 'Schema inválido.');
        }

        try {
            SavedQuery::create(Auth::id(), $title, $schema, $sql);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('salvar a consulta', $e));
        }

        $this->json(true, "Consulta \"{$title}\" salva.", [
            'savedQueries' => $this->listForResponse(),
        ]);
    }

    public function destroy(array $params = []): void
    {
        Auth::requireLogin();

        $id = (int) ($_POST['id'] ?? 0);
        $query = $id > 0 ? SavedQuery::findOwned($id, Auth::id()) : null;

        if ($query === null) {
            $this->json(false, 'Consulta não encontrada ou não pertence a você.');
        }

        try {
            SavedQuery::delete($query->id);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('remover a consulta', $e));
        }

        $this->json(true, "Consulta \"{$query->title}\" removida.", [
            'savedQueries' => $this->listForResponse(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function listForResponse(): array
    {
        return array_map(
            static fn (SavedQueryEntity $q): array => [
                'id' => $q->id,
                'title' => $q->title,
                'schema' => $q->schemaName,
                'sql' => $q->sqlText,
            ],
            SavedQuery::allForUser(Auth::id()),
        );
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
