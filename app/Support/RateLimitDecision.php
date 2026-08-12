<?php

declare(strict_types=1);

namespace App\Support;

/** Resultado de avaliar uma janela de rate limit — separado do acesso a banco pra ser testável com arrays puros. */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }

    /**
     * @param list<int> $hitTimestamps Timestamps (unix, segundos) de tentativas já registradas —
     *                                 não precisa vir pré-filtrado pela janela, isso é feito aqui.
     */
    public static function evaluate(array $hitTimestamps, int $maxAttempts, int $windowSeconds, int $now): self
    {
        $windowStart = $now - $windowSeconds;
        $recent = array_values(array_filter($hitTimestamps, static fn (int $t): bool => $t > $windowStart));
        $count = count($recent);

        if ($count < $maxAttempts) {
            return new self(allowed: true, remaining: $maxAttempts - $count, retryAfterSeconds: 0);
        }

        $oldest = min($recent);
        $retryAfter = max(0, ($oldest + $windowSeconds) - $now);

        return new self(allowed: false, remaining: 0, retryAfterSeconds: $retryAfter);
    }
}
