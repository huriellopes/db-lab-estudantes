<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Regra única de senha nova — cadastro, troca pela própria pessoa, "esqueci minha senha" e
 * reset feito por admin/professor. Antes era `strlen($senha) < 6` copiado em seis lugares.
 *
 * Por que mais rígida que a média: a mesma senha abre a conta MySQL da pessoa, e a porta do
 * MySQL é pública (ver SECURITY.md, "MySQL público"). Segue a linha do NIST SP 800-63B —
 * tamanho mínimo + lista de senhas comuns, sem exigir "1 maiúscula, 1 símbolo...", que só
 * empurra todo mundo pro mesmo "Senha@123".
 *
 * Só vale pra senha NOVA: quem já tem senha curta continua entrando normalmente.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /** bcrypt (PASSWORD_DEFAULT) ignora em silêncio tudo depois do 72º byte. */
    private const MAX_BYTES = 72;

    /** Personal info mais curta que isso não é checada — "ana" barraria "anatomia". */
    private const MIN_PERSONAL_LENGTH = 4;

    /**
     * Senhas comuns com 10+ caracteres (as menores já caem no tamanho mínimo): as mais
     * frequentes em vazamentos públicos, mais as variações em português.
     */
    private const COMMON = [
        '1234567890', '0123456789', '12345678910', '123456789a', 'a123456789', '1q2w3e4r5t',
        '1qaz2wsx3edc', 'qwertyuiop', 'qwerty1234', 'qwerty12345', 'asdfghjkl1', 'zxcvbnm123',
        'password12', 'password123', 'password1234', 'passw0rd123', 'iloveyou12', 'football12',
        'baseball12', 'princess12', 'sunshine12', 'superman12', 'welcome123', 'abc1234567',
        'abcdefghij', 'abcd123456', 'senha12345', 'senha123456', 'senhasenha', 'minhasenha',
        'minhasenha1', 'minhasenha123', 'mudar12345', 'trocar1234', 'brasil1234', 'brasil2026',
        'flamengo123', 'corinthians', 'corinthians1', 'palmeiras1', 'saopaulo123', 'vasco12345',
        'gremio1234', 'cruzeiro123', 'santos1234', 'botafogo123', 'internacional', 'estudante1',
        'estudante123', 'faculdade1', 'faculdade123', 'universidade', 'professor1', 'professor123',
        'aluno12345', 'mysql12345', 'database123', 'dblab12345', 'admin12345', 'admin123456',
        'administrador', 'root123456', 'jesus12345', 'deusefiel', 'deusefiel1', 'amor123456',
        'teamo12345', 'familia123', 'q1w2e3r4t5', 'p@ssw0rd123', 'senha@1234', 'senha@123456',
        'mudar@1234', 'brasil@123', 'admin@1234', 'abc@123456',
    ];

    /**
     * @param string ...$personalInfo Username, e-mail etc. da própria pessoa — a senha não
     *                                pode contê-los (do e-mail, vale a parte antes do "@").
     * @return string|null Mensagem de erro, ou null se a senha passou.
     */
    public static function validate(string $password, string ...$personalInfo): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return self::tooShortMessage();
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'A senha pode ter no máximo 72 bytes.';
        }

        $normalized = mb_strtolower($password);
        if (in_array($normalized, self::COMMON, true) || self::isSingleRepeatedChar($normalized)) {
            return 'Essa senha é comum demais. Escolha outra.';
        }

        foreach ($personalInfo as $info) {
            $info = mb_strtolower(explode('@', $info)[0]);
            if (mb_strlen($info) >= self::MIN_PERSONAL_LENGTH && str_contains($normalized, $info)) {
                return 'A senha não pode conter seu username ou e-mail.';
            }
        }

        return null;
    }

    public static function tooShortMessage(): string
    {
        return 'A senha deve ter pelo menos ' . self::MIN_LENGTH . ' caracteres.';
    }

    private static function isSingleRepeatedChar(string $password): bool
    {
        return count(array_unique(mb_str_split($password))) === 1;
    }
}
