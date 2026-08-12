<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\PasswordResetToken as PasswordResetTokenEntity;
use DateTimeImmutable;

final class PasswordResetToken
{
    private const TTL_MINUTES = 60;

    /**
     * Gera um token novo pro usuário e invalida qualquer token anterior ainda não usado
     * (só um link de reset válido por vez). Devolve o token em TEXTO PURO — só ele vai
     * pro e-mail; o banco guarda só o hash (ver a migration).
     */
    public static function createFor(int $userId): string
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->modify('+' . self::TTL_MINUTES . ' minutes');

        $stmt = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        );
        $stmt->execute([$userId, self::hash($plainToken), $expiresAt->format('Y-m-d H:i:s')]);

        return $plainToken;
    }

    /** Null se o token não existir, já tiver sido usado ou já ter expirado. */
    public static function findValid(string $plainToken): ?PasswordResetTokenEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = ?');
        $stmt->execute([self::hash($plainToken)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $entity = PasswordResetTokenEntity::fromRow($row);

        return $entity->isValid(new DateTimeImmutable()) ? $entity : null;
    }

    public static function markUsed(int $id): void
    {
        Database::connection()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$id]);
    }

    private static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
