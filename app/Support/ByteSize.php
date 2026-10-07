<?php

declare(strict_types=1);

namespace App\Support;

/** Tamanho legível ("1,5 MB") — filtro `|bytes` do Twig (ver App\Core\View). */
final class ByteSize
{
    public static function format(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1, ',', '.')) . ' ' . $units[$i];
    }
}
