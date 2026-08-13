<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\SchemaRecord;
use App\Services\SchemaProvisioner;
use App\Support\SchemaNameBuilder;
use Throwable;

/** POST /admin/schemas/excluir. */
final class DestroyAdminSchemaAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $dbName = trim((string) ($_POST['db_name'] ?? ''));

        if (!SchemaNameBuilder::isValidDbName($dbName)) {
            $this->respond(false, 'Schema inválido.', '/admin/schemas');
        }

        $record = SchemaRecord::findByName($dbName);
        if ($record === null) {
            $this->respond(false, 'Schema não encontrado.', '/admin/schemas');
        }

        try {
            SchemaProvisioner::dropDatabase($dbName);
            SchemaRecord::delete($record->id);
            $this->respond(true, "Schema \"{$dbName}\" removido.", '/admin/schemas');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/admin/schemas');
        }
    }
}
