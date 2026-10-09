<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Tabela app_settings — configurações que o admin muda pela plataforma (hoje: o expurgo
 * automático, ver App\Support\RetentionPolicy::configured). Nome inexistente = nunca configurado.
 */
final class AppSetting
{
    public const ARCHIVE_RETENTION_DAYS = 'archive_retention_days';

    public static function get(string $name): ?string
    {
        return self::find($name)['value'] ?? null;
    }

    /** @return ?array{value: ?string, updated_by: ?string, updated_at: string} */
    public static function find(string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT value, updated_by, updated_at FROM app_settings WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function set(string $name, ?string $value, ?string $by): void
    {
        Database::connection()->prepare(
            'INSERT INTO app_settings (name, value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP',
        )->execute([$name, $value, $by]);
    }
}
