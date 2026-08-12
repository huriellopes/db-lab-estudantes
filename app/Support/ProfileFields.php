<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validação de nome/e-mail compartilhada entre cadastro, edição de perfil, edição de
 * aluno (professor) e edição de usuário (admin) — os limites batem com as colunas
 * `users.name VARCHAR(100)` e `users.email VARCHAR(150)` (ver migration). Sem isso, um
 * valor longo demais só falhava lá na hora do INSERT/UPDATE, vazando a mensagem crua do
 * MySQL (`Data too long for column...`) pra pessoa em vez de um erro de validação normal.
 */
final class ProfileFields
{
    public const NAME_MAX_LENGTH = 100;
    public const EMAIL_MAX_LENGTH = 150;

    public static function isValidName(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= 2 && $length <= self::NAME_MAX_LENGTH;
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= self::EMAIL_MAX_LENGTH && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
