<?php

namespace App\Support;

class MysqlIdentifier
{
    /**
     * Monta o login MySQL real de um usuário: só [a-z0-9_], começa com "u<id>_",
     * máx. 32 caracteres (limite de nome de usuário do MySQL antes da 8.0.28).
     */
    public static function build(int $userId, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]) ?? '');
        $prefix = 'u' . $userId . '_';
        $login = substr($prefix . $base, 0, 32);

        return $login === $prefix ? substr($prefix . 'user', 0, 32) : $login;
    }
}
