<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\RememberToken as RememberTokenEntity;
use DateTimeImmutable;
use PDO;

/**
 * Tokens de "lembrar de mim" (ver App\Core\Auth::attemptRememberLogin) — um por
 * dispositivo/navegador, nunca compartilhado com a senha nem com a sessão PHP. Mesmo
 * padrão de App\Models\PasswordResetToken: só o hash (sha256) fica no banco.
 */
final class RememberToken
{
    /** Público porque App\Core\Auth usa o mesmo número pra expirar o cookie junto com o token. */
    public const TTL_DAYS = 30;

    /** Gera um token novo pro usuário — não mexe nos tokens de outros dispositivos. */
    public static function issueFor(int $userId): string
    {
        $pdo = Database::connection();

        // Faxina oportunista (sem cron/job separado), mesmo padrão de RateLimiter::hit().
        if (random_int(1, 20) === 1) {
            $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
        }

        return self::insert($pdo, $userId);
    }

    /** Null se o token não existir ou já ter expirado. */
    public static function findValid(string $plainToken): ?RememberTokenEntity
    {
        $stmt = Database::connection()->prepare('SELECT * FROM remember_tokens WHERE token_hash = ?');
        $stmt->execute([self::hash($plainToken)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $entity = RememberTokenEntity::fromRow($row);

        return $entity->isExpired(new DateTimeImmutable()) ? null : $entity;
    }

    /**
     * Troca o token por um novo (mesmo usuário, mesma "posição" — o dispositivo) sempre
     * que ele é usado pra reabrir sessão sozinho. Assim um cookie roubado só serve até a
     * próxima vez que a pessoa dona da conta abrir o site — depois disso o token antigo já
     * não existe mais, e usar o roubado de novo não faz nada (nem avisa ninguém, mas pelo
     * menos fecha a janela de uso).
     */
    public static function rotate(int $id, int $userId): string
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);

        return self::insert($pdo, $userId);
    }

    /** Derruba só o token desse cookie — usado no logout (não afeta outros dispositivos). */
    public static function revoke(string $plainToken): void
    {
        Database::connection()
            ->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')
            ->execute([self::hash($plainToken)]);
    }

    /**
     * Derruba os tokens de TODOS os dispositivos da conta — usado quando a senha muda ou a
     * conta é desativada/excluída. Sem isso, um cookie de "lembrar de mim" roubado
     * continuava reabrindo sessão por até TTL_DAYS mesmo depois da troca de senha.
     */
    public static function revokeAllFor(int $userId): void
    {
        Database::connection()
            ->prepare('DELETE FROM remember_tokens WHERE user_id = ?')
            ->execute([$userId]);
    }

    private static function insert(PDO $pdo, int $userId): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->modify('+' . self::TTL_DAYS . ' days');

        $stmt = $pdo->prepare(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        );
        $stmt->execute([$userId, self::hash($plainToken), $expiresAt->format('Y-m-d H:i:s')]);

        return $plainToken;
    }

    private static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
