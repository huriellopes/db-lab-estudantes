<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Regra de negócio de instituição violada — mensagem escrita aqui, segura pra mostrar na tela. */
final class InstitutionException extends RuntimeException
{
}
