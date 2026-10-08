<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Erro esperado ao ver/editar o banco de um aluno — mensagem segura pra mostrar ao professor. */
final class StudentDataException extends RuntimeException
{
}
