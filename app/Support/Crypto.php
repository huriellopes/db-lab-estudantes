<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Criptografia simétrica (libsodium) pra dados pequenos e sensíveis que precisam
 * transitar pela sessão do PHP — hoje, só a senha em texto puro cacheada no login pra
 * abrir a conexão MySQL "como a própria pessoa" no console SQL (ver Auth::mysqlPassword()).
 *
 * NUNCA usada pra senha em repouso no banco — isso continua sempre com password_hash()
 * (bcrypt/argon2, ver UserManager). Aqui é só pra não deixar a senha em texto puro sentada
 * sem proteção no arquivo de sessão em disco.
 */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return base64_encode($nonce . $cipher);
    }

    /** @return string|null Null se o valor estiver corrompido/adulterado ou a chave não bater. */
    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $appKey = Config::get('APP_KEY');
        if ($appKey === null || $appKey === '') {
            throw new RuntimeException('APP_KEY não configurada.');
        }

        $key = base64_decode($appKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY inválida — precisa decodificar pra 32 bytes em base64.');
        }

        return $key;
    }
}
