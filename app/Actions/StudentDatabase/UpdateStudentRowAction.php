<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Auth;
use App\Services\StudentDataEditor;
use App\Services\StudentDataException;
use Throwable;

final class UpdateStudentRowAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $table = rawurldecode((string) ($params['tabela'] ?? ''));
        $this->guard($db);
        $back = '/professor/bancos/' . rawurlencode($db) . '/' . rawurlencode($table);

        try {
            StudentDataEditor::update($this->professorConnection($db), Auth::user(), $db, $table, (array) ($_POST['chave'] ?? []), (array) ($_POST['valor'] ?? []), (array) ($_POST['nulo'] ?? []));
            $this->respond(true, 'Linha atualizada.', $back);
        } catch (StudentDataException $e) {
            $this->respond(false, $e->getMessage(), $back);
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('alterar a linha', $e), $back);
        }
    }
}
