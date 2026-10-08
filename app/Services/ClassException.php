<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Regra de negócio de turma violada — mensagem segura pra mostrar na tela. */
final class ClassException extends RuntimeException
{
}
