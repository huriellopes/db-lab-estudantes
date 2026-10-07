<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IP real de quem fez a requisição — usado nas chaves de rate limit (login, cadastro,
 * "esqueci minha senha").
 *
 * Lê só `REMOTE_ADDR`, de propósito. A app fica atrás do Nginx Proxy Manager, e quem troca
 * o IP do proxy pelo IP do visitante é o módulo real_ip do nginx do próprio container (ver
 * docker/nginx.conf): ele aceita `X-Forwarded-For` só quando a conexão vem de um proxy
 * confiável e pega o IP mais à direita que não é proxy — o que o NPM acrescentou, não o que
 * o cliente mandou. Ler `X-Forwarded-For` aqui no PHP (como era antes, pegando o primeiro IP
 * da cadeia) deixava o cliente escolher o próprio IP: trocar o header a cada request zerava
 * o rate limit.
 */
final class ClientIp
{
    /** @param array<string, mixed> $server Normalmente $_SERVER. */
    public static function resolve(array $server): string
    {
        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }
}
