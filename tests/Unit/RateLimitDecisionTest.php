<?php

declare(strict_types=1);

use App\Support\RateLimitDecision;

it('allows when there are fewer hits than the max, reporting how many attempts remain', function () {
    $decision = RateLimitDecision::evaluate([100, 101], maxAttempts: 5, windowSeconds: 60, now: 105);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(3)
        ->and($decision->retryAfterSeconds)->toBe(0);
});

it('allows on the very first hit (empty history)', function () {
    $decision = RateLimitDecision::evaluate([], maxAttempts: 5, windowSeconds: 60, now: 1000);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(5);
});

it('blocks once the count reaches maxAttempts within the window', function () {
    $decision = RateLimitDecision::evaluate([100, 101, 102], maxAttempts: 3, windowSeconds: 60, now: 105);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->remaining)->toBe(0);
});

it('ignores hits older than the window when counting', function () {
    // janela de 60s a partir de now=200 -> corte em 140; só o hit em 150 conta.
    $decision = RateLimitDecision::evaluate([50, 90, 150], maxAttempts: 3, windowSeconds: 60, now: 200);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(2);
});

it('computes retryAfterSeconds from the oldest hit still inside the window', function () {
    // hit mais antigo dentro da janela em 100, janela de 60s -> libera em 160.
    $decision = RateLimitDecision::evaluate([100, 110, 120], maxAttempts: 3, windowSeconds: 60, now: 130);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->retryAfterSeconds)->toBe(30);
});

it('treats a hit exactly at the window edge as expired (strictly greater than cutoff)', function () {
    // now=200, window=60 -> corte em 140. Um hit bem em 140 não conta (só > 140 conta).
    $decision = RateLimitDecision::evaluate([140], maxAttempts: 1, windowSeconds: 60, now: 200);

    expect($decision->allowed)->toBeTrue();
});
