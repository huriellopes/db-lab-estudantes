<?php

declare(strict_types=1);

namespace App\Support;

final readonly class FlashMessage
{
    public function __construct(
        public FlashType $type,
        public string $message,
    ) {
    }
}
