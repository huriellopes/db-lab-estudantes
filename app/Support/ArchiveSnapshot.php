<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Versão de exibição de uma linha arquivada (tela /admin/excluidos). O arquivo em si guarda
 * a linha inteira — inclusive password_hash, senão restaurar um usuário o deixaria sem senha —
 * mas a tela nunca mostra segredo nem texto gigante (diagrama ER, SQL).
 */
final class ArchiveSnapshot
{
    private const SECRET_KEYS = '/pass|token|secret|hash/i';
    private const MAX_CHARS = 300;

    /**
     * @param array<string, mixed> $values
     * @return array<string, scalar|null>
     */
    public static function forDisplay(array $values): array
    {
        $display = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SECRET_KEYS, $key) === 1) {
                $display[$key] = '[omitido]';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            }
            $display[$key] = is_string($value) && mb_strlen($value) > self::MAX_CHARS
                ? mb_substr($value, 0, self::MAX_CHARS) . '…'
                : $value;
        }

        return $display;
    }
}
