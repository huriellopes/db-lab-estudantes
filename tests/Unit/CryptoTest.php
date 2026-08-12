<?php

declare(strict_types=1);

use App\Support\Crypto;

it('decrypts back to the original plaintext', function () {
    $encrypted = Crypto::encrypt('senha-super-secreta');

    expect(Crypto::decrypt($encrypted))->toBe('senha-super-secreta');
});

it('produces a different ciphertext each time (random nonce), even for the same input', function () {
    $a = Crypto::encrypt('mesma coisa');
    $b = Crypto::encrypt('mesma coisa');

    expect($a)->not->toBe($b)
        ->and(Crypto::decrypt($a))->toBe('mesma coisa')
        ->and(Crypto::decrypt($b))->toBe('mesma coisa');
});

it('round-trips unicode and empty strings', function () {
    expect(Crypto::decrypt(Crypto::encrypt('sénhã com açento çãé')))->toBe('sénhã com açento çãé')
        ->and(Crypto::decrypt(Crypto::encrypt('')))->toBe('');
});

it('returns null for garbage that is not valid base64/ciphertext', function () {
    expect(Crypto::decrypt('isso não é um valor criptografado válido'))->toBeNull()
        ->and(Crypto::decrypt(''))->toBeNull()
        ->and(Crypto::decrypt('dGVzdGU='))->toBeNull();
});

it('returns null when the ciphertext has been tampered with', function () {
    $encrypted = Crypto::encrypt('valor original');
    $tampered = substr($encrypted, 0, -4) . 'AAAA';

    expect(Crypto::decrypt($tampered))->toBeNull();
});

it('throws when APP_KEY is missing or malformed', function () {
    $original = getenv('APP_KEY');
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    try {
        expect(fn () => Crypto::encrypt('x'))->toThrow(RuntimeException::class);
    } finally {
        putenv("APP_KEY={$original}");
        $_ENV['APP_KEY'] = $original;
    }
});
