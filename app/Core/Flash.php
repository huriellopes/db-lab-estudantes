<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\FlashMessage;
use App\Support\FlashType;

final class Flash
{
    public static function set(FlashType $type, string $message): void
    {
        $_SESSION['flash'] = new FlashMessage($type, $message);
    }

    public static function get(): ?FlashMessage
    {
        if (empty($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flash instanceof FlashMessage ? $flash : null;
    }
}
