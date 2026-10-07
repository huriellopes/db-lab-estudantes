<?php

declare(strict_types=1);

use App\Support\PasswordPolicy;

it('accepts a long, uncommon password', function () {
    expect(PasswordPolicy::validate('cavalo-bateria-grampo'))->toBeNull();
});

it('rejects a password shorter than the minimum', function () {
    expect(PasswordPolicy::validate('Ab1!xyz'))->toBe(PasswordPolicy::tooShortMessage())
        ->and(PasswordPolicy::validate(str_repeat('k', PasswordPolicy::MIN_LENGTH - 1)))->toBe(PasswordPolicy::tooShortMessage());
});

it('counts characters, not bytes, for the minimum length', function () {
    // 10 caracteres acentuados = 20 bytes — tem que passar pelo tamanho, não por sorte.
    expect(PasswordPolicy::validate('ãéíõúçâêôà'))->toBeNull();
});

it('rejects a password over 72 bytes (bcrypt would silently ignore the rest)', function () {
    expect(PasswordPolicy::validate(str_repeat('ab', 37)))->toBe('A senha pode ter no máximo 72 bytes.');
});

it('rejects common passwords regardless of case', function () {
    expect(PasswordPolicy::validate('1234567890'))->toBe('Essa senha é comum demais. Escolha outra.')
        ->and(PasswordPolicy::validate('SENHA12345'))->toBe('Essa senha é comum demais. Escolha outra.')
        ->and(PasswordPolicy::validate('qwertyuiop'))->toBe('Essa senha é comum demais. Escolha outra.');
});

it('rejects a single repeated character', function () {
    expect(PasswordPolicy::validate('aaaaaaaaaaaa'))->toBe('Essa senha é comum demais. Escolha outra.');
});

it('rejects a password containing the username or the e-mail local part', function () {
    expect(PasswordPolicy::validate('joaosilva2026', 'joaosilva', 'joao.silva@example.com'))
        ->toBe('A senha não pode conter seu username ou e-mail.')
        ->and(PasswordPolicy::validate('xx-joao.silva-xx', 'outro', 'joao.silva@example.com'))
        ->toBe('A senha não pode conter seu username ou e-mail.');
});

it('ignores very short personal info (would reject too much by coincidence)', function () {
    expect(PasswordPolicy::validate('anatomia-de-grey', 'ana'))->toBeNull();
});
