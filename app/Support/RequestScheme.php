<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detecta se a requisição chegou por HTTPS — necessário porque a app fica atrás do
 * Nginx Proxy Manager (faz o TLS termination) em produção: o PHP-FPM só enxerga HTTP
 * "puro" internamente, então `$_SERVER['HTTPS']` nunca vem preenchido sozinho. O proxy
 * reverso propaga isso via `X-Forwarded-Proto` (padrão da indústria).
 */
final class RequestScheme
{
    /** @param array<string, mixed> $server Normalmente $_SERVER. */
    public static function isHttps(array $server): bool
    {
        $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($forwardedProto) && strtolower(trim(explode(',', $forwardedProto)[0])) === 'https') {
            return true;
        }

        $https = $server['HTTPS'] ?? null;

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }
}
