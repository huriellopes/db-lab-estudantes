<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Token CSRF (synchronizer token pattern): um token por sessão, reaproveitado em todos os
 * forms/requisições AJAX enquanto ela durar — gerado na primeira vez que alguém pede
 * (inclusive antes de logar, já que o form de login/registro também precisa dele).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /** Compara o token enviado com o da sessão atual. */
    public static function verify(?string $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return self::matches(is_string($expected) ? $expected : null, $submitted);
    }

    /** Lógica pura de comparação — testável sem precisar de sessão. */
    public static function matches(?string $expected, ?string $submitted): bool
    {
        return is_string($expected) && $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
