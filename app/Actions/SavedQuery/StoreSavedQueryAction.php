<?php

declare(strict_types=1);

namespace App\Actions\SavedQuery;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\SavedQuery as SavedQueryEntity;
use App\Models\SavedQuery;
use App\Support\SchemaNameBuilder;
use Throwable;

/**
 * Salva um comando SQL na biblioteca pessoal (ver Alpine `sqlConsole` em
 * resources/js/app.js). POST /consultas-salvas.
 */
final class StoreSavedQueryAction extends Action
{
    private const MAX_TITLE_LENGTH = 80;

    public function __invoke(array $params = []): void
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
            'savedQueries' => array_map(
                static fn (SavedQueryEntity $q): array => $q->toSummaryArray(),
                SavedQuery::allForUser(Auth::id()),
            ),
        ]);
    }
}
