<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Action;
use App\Core\Auth;
use App\Core\View;
use App\Services\StudentDatabases;
use PDO;

/** Base das telas de bancos dos alunos: escopo do professor e conexão como ele. */
abstract class StudentDatabaseAction extends Action
{
    /** Banco fora do escopo → 404 (não revela que existe). */
    protected function guard(string $db): void
    {
        Auth::requireProfessorOrAdmin();
        if (!StudentDatabases::canAccess(Auth::user(), $db)) {
            http_response_code(404);
            echo View::render('errors/404');
            exit;
        }
    }

    /** Conexão como o professor, ou a tela de confirmar senha (GET) / JSON pedindo a senha (POST). */
    protected function professorConnection(string $db): PDO
    {
        if (Auth::isImpersonating()) {
            $this->respond(false, Auth::IMPERSONATION_NO_MYSQL, '/professor/bancos');
        }
        $pdo = StudentDatabases::connectionFor(Auth::user());
        if ($pdo !== null) {
            return $pdo;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->json(false, 'Confirme sua senha para continuar.', ['needsMysqlPassword' => true]);
        }
        $this->render('professor/databases/_password', ['pageTitle' => 'Confirme sua senha', 'db' => $db]);
        exit;
    }
}
