<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Regra pura de expiração da sessão logada (ver App\Core\Auth::enforceSession): por
 * inatividade e por idade absoluta desde o login. Sem isso, a sessão vivia até o GC do PHP
 * resolver apagar o arquivo — uma aba esquecida aberta num computador de laboratório
 * continuava logada indefinidamente.
 *
 * Quem marcou "Manter conectado" não sente a diferença: o cookie de lembrar reabre uma
 * sessão nova sozinho (Auth::attemptRememberLogin), só que sem a senha MySQL em cache.
 */
final class SessionTimeout
{
    /** 2h sem nenhuma requisição. */
    public const IDLE_SECONDS = 2 * 60 * 60;

    /** 12h desde o login, mesmo com uso contínuo — limita a vida de uma sessão roubada. */
    public const ABSOLUTE_SECONDS = 12 * 60 * 60;

    public static function isExpired(int $now, ?int $lastActivityAt, ?int $loggedInAt): bool
    {
        if ($lastActivityAt !== null && $now - $lastActivityAt > self::IDLE_SECONDS) {
            return true;
        }

        return $loggedInAt !== null && $now - $loggedInAt > self::ABSOLUTE_SECONDS;
    }
}
