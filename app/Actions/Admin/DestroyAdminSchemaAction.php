<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\SchemaRecord;
use App\Services\ArchiveException;
use App\Services\Archiver;
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
            Archiver::archive('schema', $record->id, 'schema.deleted', ['por' => 'admin']);
            $this->respond(true, "Schema \"{$dbName}\" movido para Dados excluídos.", '/admin/schemas');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/schemas');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/admin/schemas');
        }
    }
}
