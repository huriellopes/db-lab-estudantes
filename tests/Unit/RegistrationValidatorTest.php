<?php

declare(strict_types=1);

use App\Support\RegistrationValidator;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maria Souza',
        'email' => 'maria@example.com',
        'username' => 'mariasouza',
        'password' => 'segredo-forte-123',
        'passwordConfirm' => 'segredo-forte-123',
        'emailExists' => false,
        'usernameExists' => false,
    ], $overrides);
}

it('passes with valid data', function () {
    $data = validRegistration();

    $errors = RegistrationValidator::validate(...array_values($data));

    expect($errors)->toBe([]);
});

it('rejects a name shorter than 2 characters', function () {
    $data = validRegistration(['name' => 'M']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('Informe seu nome completo.');
});

it('rejects an invalid email', function () {
    $data = validRegistration(['email' => 'not-an-email']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('Informe um e-mail válido.');
});

it('rejects an invalid username', function () {
    $data = validRegistration(['username' => 'ab']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('Username inválido. Use 3 a 32 caracteres (minúsculas, números e "_"), começando com letra, sem "__" e sem terminar em "_".');
});

it('rejects a password that fails the password policy', function () {
    $data = validRegistration(['password' => '123', 'passwordConfirm' => '123']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('A senha deve ter pelo menos 10 caracteres.');
});

it('rejects a password containing the chosen username', function () {
    $data = validRegistration(['password' => 'mariasouza!!', 'passwordConfirm' => 'mariasouza!!']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('A senha não pode conter seu username ou e-mail.');
});

it('rejects mismatched password confirmation', function () {
    $data = validRegistration(['passwordConfirm' => 'outra-senha']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('As senhas não conferem.');
});

it('rejects when the email already exists, but only if nothing else failed first', function () {
    $data = validRegistration(['emailExists' => true]);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toBe(['Já existe uma conta com este e-mail.']);
});

it('rejects when the username already exists, but only if nothing else failed first', function () {
    $data = validRegistration(['usernameExists' => true]);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toBe(['Esse username já está em uso.']);
});

it('does not pile on the "already exists" errors when other fields are also invalid', function () {
    $data = validRegistration(['name' => '', 'emailExists' => true, 'usernameExists' => true]);

    $errors = RegistrationValidator::validate(...array_values($data));

    expect($errors)->not->toContain('Já existe uma conta com este e-mail.')
        ->and($errors)->not->toContain('Esse username já está em uso.');
});
