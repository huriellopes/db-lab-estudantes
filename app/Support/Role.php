<?php

declare(strict_types=1);

namespace App\Support;

// Enums não podem implementar __toString() em PHP — por isso qualquer lugar que precise
// exibir o papel (Twig, mensagens) usa ->label() ou ->value explicitamente.
enum Role: string
{
    case Aluno = 'aluno';
    case Professor = 'professor';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Aluno => 'Aluno',
            self::Professor => 'Professor',
            self::Admin => 'Admin',
        };
    }

    /** Papéis que uma pessoa pode escolher no formulário de cadastro (admin nunca se autocadastra). */
    public static function registrable(): array
    {
        return [self::Aluno, self::Professor];
    }
}
