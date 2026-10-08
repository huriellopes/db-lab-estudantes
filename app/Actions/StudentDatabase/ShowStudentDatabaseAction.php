<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Services\StudentDatabases;

/** Estrutura de um banco de aluno: tabelas, colunas, chaves e relações. GET /professor/bancos/{banco}. */
final class ShowStudentDatabaseAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $this->guard($db);
        $tables = StudentDatabases::structure($this->professorConnection($db), $db);

        $relations = [];
        foreach ($tables as $t) {
            foreach ($t['foreignKeys'] as $fk) {
                $relations[] = ['from' => "{$t['name']}.{$fk['column']}", 'to' => "{$fk['refTable']}.{$fk['refColumn']}"];
            }
        }

        $this->render('professor/databases/show', [
            'pageTitle' => $db,
            'db' => $db,
            'owner' => StudentDatabases::ownerName($db),
            'tables' => $tables,
            'relations' => $relations,
        ]);
    }
}
