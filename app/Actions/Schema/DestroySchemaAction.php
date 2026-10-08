<?php

declare(strict_types=1);

namespace App\Actions\Schema;

use App\Core\Action;
use App\Core\Auth;
use App\Models\SchemaRecord;
use App\Services\ArchiveException;
use App\Services\Archiver;
use App\Support\SchemaNameBuilder;
use Throwable;

/** POST /schemas/delete. */
final class DestroySchemaAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $dbName = trim((string) ($_POST['db_name'] ?? ''));

        // A posse é decidida pela tabela schemas_criados (por user_id), não pelo prefixo
        // do mysql_login atual: a pessoa pode ter renomeado o login MySQL depois de criar
        // o schema, e o nome do database em si não muda quando isso acontece.
        $schema = SchemaNameBuilder::isValidDbName($dbName)
            ? SchemaRecord::findOwned($dbName, Auth::id())
            : null;

        if ($schema === null) {
            $this->respond(false, 'Schema não encontrado ou não pertence a você.', '/dashboard');
        }

        try {
            Archiver::archive('schema', $schema->id, 'schema.deleted');
            $this->respond(true, "Schema \"{$dbName}\" removido. Se precisar dele de volta, um admin consegue restaurar.", '/dashboard');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/dashboard');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/dashboard');
        }
    }
}
