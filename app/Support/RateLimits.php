<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limites de tentativa do login e do cadastro, num lugar só. Dimensionados para SALA DE AULA:
 * a turma inteira sai pelo mesmo IP público da escola (NAT), então um limite por IP baixo trata a
 * sala como uma pessoa só — em 06/10/2026 o 9º cadastro da sala travou todos por 12 minutos.
 *
 * O que protege contra força bruta de verdade é o limite por CONTA (6 senhas erradas por conta) e
 * o connection_control do MySQL; o limite por IP só segura abuso em massa vindo de um lugar.
 *
 * @phpstan-type Bucket array{0: string, 1: int, 2: int} chave, máximo de tentativas, janela em segundos
 */
final class RateLimits
{
    public const WINDOW_SECONDS = 300;

    private const LOGIN_FAILURES_PER_IP = 60;
    private const LOGIN_FAILURES_PER_ACCOUNT = 6;
    private const REGISTER_PER_IP = 30;
    /** Com código de instituição válido (que só o professor distribui): uma turma grande inteira. */
    private const REGISTER_PER_INVITE_CODE = 200;

    /** @return array{0: string, 1: int, 2: int} */
    public static function loginPerIp(string $ip): array
    {
        return ['login:ip:' . $ip, self::LOGIN_FAILURES_PER_IP, self::WINDOW_SECONDS];
    }

    /** @return array{0: string, 1: int, 2: int} */
    public static function loginPerAccount(string $identifier): array
    {
        return ['login:id:' . mb_strtolower($identifier), self::LOGIN_FAILURES_PER_ACCOUNT, self::WINDOW_SECONDS];
    }

    /**
     * @param ?string $validInviteCode Código de instituição já validado (existe) — null sem código.
     * @return array{0: string, 1: int, 2: int}
     */
    public static function register(string $ip, ?string $validInviteCode): array
    {
        return $validInviteCode !== null
            ? ['register:code:' . $validInviteCode, self::REGISTER_PER_INVITE_CODE, self::WINDOW_SECONDS]
            : ['register:ip:' . $ip, self::REGISTER_PER_IP, self::WINDOW_SECONDS];
    }
}
