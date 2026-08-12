<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IP real de quem fez a requisição — necessário porque a app fica atrás do Nginx Proxy
 * Manager em produção: `$_SERVER['REMOTE_ADDR']` sempre seria o IP do proxy, não do
 * visitante. O proxy propaga o IP original via `X-Forwarded-For` (padrão da indústria).
 */
final class ClientIp
{
    /** @param array<string, mixed> $server Normalmente $_SERVER. */
    public static function resolve(array $server): string
    {
        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwardedFor)) {
            // Pode vir uma cadeia "cliente, proxy1, proxy2" — o primeiro é o original.
            $first = trim(explode(',', $forwardedFor)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }
}
