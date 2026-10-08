<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Código que o aluno digita no cadastro pra já entrar na instituição (ver RegisterAction).
 * Sem 0/O e 1/I: é lido no quadro e digitado à mão.
 */
final class InviteCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const PATTERN = '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }

    /** Aceita minúsculas, espaços e hífen opcional; devolve null se não for um código válido. */
    public static function normalize(string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s-]+/', '', $input));
        if (strlen($compact) !== 8) {
            return null;
        }
        $code = substr($compact, 0, 4) . '-' . substr($compact, 4);

        return preg_match(self::PATTERN, $code) === 1 ? $code : null;
    }
}
