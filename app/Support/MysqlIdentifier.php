<?php

declare(strict_types=1);

namespace App\Support;

final class MysqlIdentifier
{
    private const CUSTOM_LOGIN_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/';

    /** Nomes que ninguém pode assumir como login MySQL — contas do sistema/da app. */
    private const RESERVED = [
        'root', 'appuser', 'admin', 'administrator', 'mysql', 'phpmyadmin',
        'mysql.sys', 'mysql.session', 'mysql.infoschema',
    ];

    /**
     * Monta o login MySQL real de um usuário no cadastro: só [a-z0-9_], começa com
     * "u<id>_", máx. 32 caracteres (limite de nome de usuário do MySQL antes da 8.0.28).
     */
    public static function build(int $userId, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]) ?? '');
        $prefix = 'u' . $userId . '_';
        $login = substr($prefix . $base, 0, 32);

        return $login === $prefix ? substr($prefix . 'user', 0, 32) : $login;
    }

    /**
     * Valida um login MySQL escolhido livremente pela pessoa (ex.: ao renomear a conta
     * usada no phpMyAdmin): letras minúsculas, números e "_", começando com letra,
     * 3 a 32 caracteres, e fora da lista de nomes reservados.
     *
     * Sem "__" e sem "_" no fim: o login vira prefixo de schema ("<login>__label", ver
     * App\Support\SchemaNameBuilder), e com essas duas regras o primeiro "__" do nome de um
     * database sempre marca onde o prefixo termina. Sem elas, o GRANT com wildcard de um
     * login ("ab" → `ab\_\_%`) também alcançaria schemas de outro ("ab__cd" → ab__cd__x).
     */
    public static function isValidCustomLogin(string $login): bool
    {
        return (bool) preg_match(self::CUSTOM_LOGIN_PATTERN, $login)
            && !str_contains($login, '__')
            && !str_ends_with($login, '_')
            && !in_array($login, self::RESERVED, true);
    }
}
