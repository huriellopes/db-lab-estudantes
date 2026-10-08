<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\View;
use App\Services\StudentDatabases;

/** Dados de uma tabela de aluno, com editar/inserir. GET /professor/bancos/{banco}/{tabela}. */
final class ShowStudentTableAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $tableName = rawurldecode((string) ($params['tabela'] ?? ''));
        $this->guard($db);
        $pdo = $this->professorConnection($db);

        $table = StudentDatabases::table($pdo, $db, $tableName);
        if ($table === null) {
            http_response_code(404);
            echo View::render('errors/404');

            return;
        }

        $this->render('professor/databases/table', [
            'pageTitle' => "{$db}.{$tableName}",
            'db' => $db,
            'owner' => StudentDatabases::ownerName($db),
            'table' => $table,
            'data' => StudentDatabases::rows($pdo, $db, $table, (int) ($_GET['pagina'] ?? 1)),
        ]);
    }
}
