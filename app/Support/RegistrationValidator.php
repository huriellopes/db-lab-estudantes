<?php

declare(strict_types=1);

namespace App\Support;

final class RegistrationValidator
{
    /**
     * Validação pura do formulário de cadastro (sempre papel "aluno" — ver
     * Role::registrable()). $emailExists/$usernameExists são calculados fora (pelo
     * chamador, via consulta ao banco) para manter esta classe sem dependência de I/O.
     *
     * @return string[] lista de mensagens de erro (vazia = válido)
     */
    public static function validate(
        string $name,
        string $email,
        string $username,
        string $password,
        string $passwordConfirm,
        bool $emailExists,
        bool $usernameExists,
    ): array {
        $errors = [];

        if (!ProfileFields::isValidName($name)) {
            $errors[] = 'Informe seu nome completo.';
        }
        if (!ProfileFields::isValidEmail($email)) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if (!MysqlIdentifier::isValidCustomLogin($username)) {
            $errors[] = 'Username inválido. Use 3 a 32 caracteres (minúsculas, números e "_"), começando com letra, sem "__" e sem terminar em "_".';
        }
        $passwordError = PasswordPolicy::validate($password, $username, $email);
        if ($passwordError !== null) {
            $errors[] = $passwordError;
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'As senhas não conferem.';
        }
        if (!$errors && $emailExists) {
            $errors[] = 'Já existe uma conta com este e-mail.';
        }
        if (!$errors && $usernameExists) {
            $errors[] = 'Esse username já está em uso.';
        }

        return $errors;
    }
}
