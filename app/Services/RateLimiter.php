<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\RateLimitDecision;
use PDO;

/**
 * Rate limiting simples, guardado no MySQL (sem depender de Redis/memcached — não vale a
 * complexidade extra pra um lab de estudos). Cada "hit" é uma linha; a decisão em si
 * (App\Support\RateLimitDecision) é pura e testada isoladamente.
 */
final class RateLimiter
{
    public static function check(string $key, int $maxAttempts, int $windowSeconds): RateLimitDecision
    {
        $stmt = Database::connection()->prepare(
            'SELECT UNIX_TIMESTAMP(created_at) FROM rate_limit_hits
             WHERE bucket_key = ? AND created_at > (NOW() - INTERVAL ? SECOND)',
        );
        $stmt->execute([$key, $windowSeconds]);
        /** @var list<int> $timestamps */
        $timestamps = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return RateLimitDecision::evaluate($timestamps, $maxAttempts, $windowSeconds, time());
    }

    public static function hit(string $key): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO rate_limit_hits (bucket_key) VALUES (?)')->execute([$key]);

        // Faxina oportunista (sem cron/job separado): 1 em ~50 hits limpa registros com
        // mais de 1 dia, bem além de qualquer janela usada na app. Mantém a tabela pequena.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }
}
