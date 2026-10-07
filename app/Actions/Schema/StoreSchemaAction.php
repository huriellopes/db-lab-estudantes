<?php

declare(strict_types=1);

namespace App\Actions\Schema;

use App\Core\Action;
use App\Core\Auth;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\SchemaProvisioner;
use App\Support\SchemaNameBuilder;
use Throwable;

/** POST /schemas. */
final class StoreSchemaAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $label = trim((string) ($_POST['label'] ?? ''));

        if (!SchemaNameBuilder::isValidLabel($label)) {
            $this->respond(false, 'Nome de schema inválido. Use apenas letras, números e "_", começando com letra ou número.', '/dashboard');
        }

        // Lido do banco, não da sessão: o prefixo (users.schema_prefix) é o que manda no nome
        // e no GRANT com wildcard, e o snapshot da sessão pode estar desatualizado.
        $user = User::find(Auth::id());
        if ($user === null) {
            $this->respond(false, 'Conta não encontrada.', '/dashboard');
        }

        $dbName = SchemaNameBuilder::build($user->schemaPrefix, $label);

        if (!SchemaNameBuilder::isWithinLengthLimit($dbName)) {
            $this->respond(false, 'Nome de schema muito longo.', '/dashboard');
        }

        if (SchemaRecord::nameTaken($dbName)) {
            $this->respond(false, 'Você já tem um schema com esse nome.', '/dashboard');
        }

        // Sem transação PDO aqui: CREATE DATABASE/GRANT são DDL (ver aviso em SchemaProvisioner).
        $databaseCreated = false;

        try {
            SchemaProvisioner::createDatabase($dbName, $user->mysqlLogin);
            $databaseCreated = true;

            SchemaRecord::create(Auth::id(), $dbName);

            $this->respond(true, "Schema \"{$dbName}\" criado com sucesso.", '/dashboard');
        } catch (Throwable $e) {
            if ($databaseCreated) {
                try {
                    SchemaProvisioner::dropDatabase($dbName);
                } catch (Throwable $cleanupError) {
                    // ignora falha de limpeza, o erro principal já será reportado abaixo
                }
            }
            $this->respond(false, $this->genericError('criar o schema', $e), '/dashboard');
        }
    }
}
