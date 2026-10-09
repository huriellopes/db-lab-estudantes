<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Models\AppSetting;
use DateTimeImmutable;

/**
 * Expurgo automático de Dados excluídos: lote com mais de N dias é excluído definitivamente (ver
 * App\Services\Archiver::purgeExpired). 0, vazio ou inválido = desligado. Lote marcado "não
 * apagar" nunca expira.
 *
 * N vem do que o admin salvou em /admin/excluidos (app_settings); enquanto ninguém salvou, vale
 * ARCHIVE_RETENTION_DAYS do .env. Um "0" salvo pela tela desliga mesmo com o .env preenchido.
 */
final class RetentionPolicy
{
    /** Dias em vigor agora (lê o banco). */
    public static function current(): int
    {
        return self::resolve(AppSetting::get(AppSetting::ARCHIVE_RETENTION_DAYS), Config::get('ARCHIVE_RETENTION_DAYS'));
    }

    /** O valor salvo pela tela (null = nunca salvo) tem precedência sobre o do .env. */
    public static function resolve(?string $saved, ?string $env): int
    {
        return self::days($saved ?? $env);
    }

    /** De onde vem o valor em vigor: 'tela', 'env' ou 'padrão' (nada configurado = desligado). */
    public static function source(?string $saved, ?string $env): string
    {
        return match (true) {
            $saved !== null => 'tela',
            self::days($env) > 0 => 'env',
            default => 'padrão',
        };
    }

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
