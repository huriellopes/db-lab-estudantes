<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Expurgo automático de Dados excluídos: lote com mais de ARCHIVE_RETENTION_DAYS dias é excluído
 * definitivamente (ver App\Services\Archiver::purgeExpired). 0, vazio ou inválido = desligado.
 * Lote marcado "não apagar" nunca expira.
 */
final class RetentionPolicy
{
    public static function days(?string $configured): int
    {
        $value = trim((string) $configured);

        return ctype_digit($value) ? (int) $value : 0;
    }

    public static function expiresAt(DateTimeImmutable $deletedAt, int $days, bool $keep): ?DateTimeImmutable
    {
        return $days > 0 && !$keep ? $deletedAt->modify("+{$days} days") : null;
    }

    /** Dias que faltam (arredondado pra cima; 0 = já venceu); null = não expira. */
    public static function daysLeft(DateTimeImmutable $deletedAt, int $days, bool $keep, DateTimeImmutable $now): ?int
    {
        $expires = self::expiresAt($deletedAt, $days, $keep);
        if ($expires === null) {
            return null;
        }
        $seconds = $expires->getTimestamp() - $now->getTimestamp();

        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }
}
