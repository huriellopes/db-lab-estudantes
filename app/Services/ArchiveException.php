<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Falha esperada do arquivo de excluídos (conflito ao restaurar, schema que sumiu...). A
 * mensagem é escrita aqui mesmo e é segura pra mostrar na tela — diferente de um
 * PDOException, que passa por Controller::genericError().
 */
final class ArchiveException extends RuntimeException
{
    /** @param list<string> $orphanedObjects Definições que não puderam ser recriadas (ver SchemaQuarantine::move). */
    public function __construct(string $message, public readonly array $orphanedObjects = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
