<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Action;
use App\Core\Auth;
use App\Services\StudentDatabases;
use App\Support\Role;

/** Bancos dos alunos das instituições do professor. GET /professor/bancos. */
final class IndexStudentDatabasesAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $this->render('professor/databases/index', [
            'pageTitle' => 'Bancos dos alunos',
            'groups' => StudentDatabases::visibleTo(Auth::user()),
            'isAdmin' => Auth::user()->role === Role::Admin,
        ]);
    }
}
