<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Institution;
use App\Models\SchoolClass;
use App\Support\InviteCode;

/**
 * Códigos de convite são únicos entre instituições E turmas (inclusive os reservados no
 * arquivo): o mesmo campo "Entrar com código" do dashboard aceita os dois, então um código
 * nunca pode significar duas coisas.
 */
final class InviteCodes
{
    public static function taken(string $code): bool
    {
        return Institution::inviteCodeTaken($code) || SchoolClass::inviteCodeTaken($code);
    }

    public static function fresh(): string
    {
        do {
            $code = InviteCode::generate();
        } while (self::taken($code));

        return $code;
    }
}
