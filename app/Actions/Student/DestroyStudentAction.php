<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** POST /professor/alunos/{id}/excluir. */
final class DestroyStudentAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $student = $this->findStudentOrFail((int) $params['id']);

        try {
            Archiver::archive('user', $student->id, 'student.deleted', ['email' => $student->email]);
            $this->respond(true, "Conta de {$student->name} excluída (um admin pode restaurar em Dados excluídos).", '/professor/alunos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/professor/alunos');
        }
    }
}
