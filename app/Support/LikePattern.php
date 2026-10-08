<?php

declare(strict_types=1);

namespace App\Support;

/** Texto digitado vira padrão "contém" do LIKE, com % e _ tratados como texto, não curinga. */
final class LikePattern
{
    public static function contains(string $text): string
    {
        return '%' . addcslashes($text, '\\%_') . '%';
    }
}
