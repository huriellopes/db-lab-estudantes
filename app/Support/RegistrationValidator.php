<?php

namespace App\Support;

class RegistrationValidator
{
    public const ROLES = ['aluno', 'professor'];

    /**
     * Validação pura do formulário de cadastro. $emailExists é calculado fora (pelo
     * chamador, via consulta ao banco) para manter esta classe sem dependência de I/O.
     *
     * @return string[] lista de mensagens de erro (vazia = válido)
     */
    public static function validate(
        string $name,
        string $email,
        string $password,
        string $passwordConfirm,
        string $role,
        bool $emailExists
    ): array {
        $errors = [];

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Informe seu nome completo.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors[] = 'Selecione um perfil válido.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'As senhas não conferem.';
        }
        if (!$errors && $emailExists) {
            $errors[] = 'Já existe uma conta com este e-mail.';
        }

        return $errors;
    }
}
