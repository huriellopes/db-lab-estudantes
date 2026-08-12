<?php

declare(strict_types=1);

namespace App\Support;

enum FlashType: string
{
    case Success = 'success';
    case Error = 'error';

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
