<?php

declare(strict_types=1);

use App\Support\RateLimits;

it('uses a 5 minute window sized for a whole class on a shared school IP', function () {
    expect(RateLimits::WINDOW_SECONDS)->toBe(300)
        ->and(RateLimits::loginPerIp('131.72.0.9'))->toBe(['login:ip:131.72.0.9', 60, 300])
        ->and(RateLimits::loginPerAccount('Maria@Escola.com'))->toBe(['login:id:maria@escola.com', 6, 300]);
});

it('counts sign-ups without code by IP, and with a valid institution code by that code', function () {
    expect(RateLimits::register('131.72.0.9', null))->toBe(['register:ip:131.72.0.9', 30, 300])
        // Código válido: a sala inteira se cadastra sem cair no limite do IP compartilhado.
        ->and(RateLimits::register('131.72.0.9', 'ABCD-EF23'))->toBe(['register:code:ABCD-EF23', 200, 300]);
});
