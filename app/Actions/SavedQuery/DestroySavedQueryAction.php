<?php

declare(strict_types=1);

namespace App\Actions\SavedQuery;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\SavedQuery as SavedQueryEntity;
use App\Models\SavedQuery;
use Throwable;

/** POST /consultas-salvas/excluir. */
final class DestroySavedQueryAction extends Action
{
    public function __invoke(array $params = []): void
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
            'savedQueries' => array_map(
                static fn (SavedQueryEntity $q): array => $q->toSummaryArray(),
                SavedQuery::allForUser(Auth::id()),
            ),
        ]);
    }
}
