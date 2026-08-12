<?php

use App\Support\RegistrationValidator;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maria Souza',
        'email' => 'maria@example.com',
        'password' => 'segredo123',
        'passwordConfirm' => 'segredo123',
        'role' => 'aluno',
        'emailExists' => false,
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

it('rejects a role outside aluno/professor', function () {
    $data = validRegistration(['role' => 'admin']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('Selecione um perfil válido.');
});

it('rejects a password shorter than 6 characters', function () {
    $data = validRegistration(['password' => '123', 'passwordConfirm' => '123']);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->toContain('A senha deve ter pelo menos 6 caracteres.');
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

it('does not pile on the "email already exists" error when other fields are also invalid', function () {
    $data = validRegistration(['name' => '', 'emailExists' => true]);

    expect(RegistrationValidator::validate(...array_values($data)))
        ->not->toContain('Já existe uma conta com este e-mail.');
});
