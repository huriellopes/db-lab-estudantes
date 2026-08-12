<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\SchemaRecord;
use App\Services\SchemaProvisioner;
use App\Support\SchemaNameBuilder;
use Throwable;

class SchemaController extends Controller
{
    public function store(array $params = []): void
    {
        Auth::requireLogin();

        $label = trim($_POST['label'] ?? '');

        if (!SchemaNameBuilder::isValidLabel($label)) {
            $this->respond(false, 'Nome de schema inválido. Use apenas letras, números e "_".', '/dashboard');
        }

        $mysqlLogin = Auth::user()['mysql_login'];
        $dbName = SchemaNameBuilder::build($mysqlLogin, $label);

        if (!SchemaNameBuilder::isWithinLengthLimit($dbName)) {
            $this->respond(false, 'Nome de schema muito longo.', '/dashboard');
        }

        if (SchemaRecord::nameTaken($dbName)) {
            $this->respond(false, 'Você já tem um schema com esse nome.', '/dashboard');
        }

        // Sem transação PDO aqui: CREATE DATABASE/GRANT são DDL (ver aviso em SchemaProvisioner).
        $databaseCreated = false;

        try {
            SchemaProvisioner::createDatabase($dbName, $mysqlLogin);
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
            $this->respond(false, 'Não foi possível criar o schema: ' . $e->getMessage(), '/dashboard');
        }
    }

    public function destroy(array $params = []): void
    {
        Auth::requireLogin();

        $dbName = trim($_POST['db_name'] ?? '');
        $record = SchemaNameBuilder::isValidDbName($dbName) && SchemaNameBuilder::isOwnedBy($dbName, Auth::user()['mysql_login'])
            ? SchemaRecord::findOwned($dbName, Auth::id())
            : null;

        if (!$record) {
            $this->respond(false, 'Schema não encontrado ou não pertence a você.', '/dashboard');
        }

        try {
            SchemaProvisioner::dropDatabase($dbName);
            SchemaRecord::delete($record['id']);
            $this->respond(true, "Schema \"{$dbName}\" removido.", '/dashboard');
        } catch (Throwable $e) {
            $this->respond(false, 'Não foi possível remover o schema: ' . $e->getMessage(), '/dashboard');
        }
    }
}
