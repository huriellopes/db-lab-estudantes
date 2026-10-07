<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limpa o `meta` de um registro de auditoria antes de gravar: nada de senha/token/SQL
 * inteiro no banco (a auditoria é lida por qualquer admin e entra nos backups).
 */
final class AuditMeta
{
    private const SECRET_KEYS = '/pass|senha|token|secret|hash|_csrf/i';
    private const MAX_VALUE_CHARS = 200;

    /**
     * @param array<string, mixed> $meta
     * @return array<string, scalar|null>
     */
    public static function sanitize(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SECRET_KEYS, $key) === 1) {
                $clean[$key] = '[omitido]';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            }
            $clean[$key] = is_string($value) && mb_strlen($value) > self::MAX_VALUE_CHARS
                ? mb_substr($value, 0, self::MAX_VALUE_CHARS) . '…'
                : $value;
        }

        return $clean;
    }
}
