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

    /**
     * O cadastro público é só para alunos — contas de professor/admin são criadas pelo
     * admin (App\Controllers\AdminController) ou promovidas depois via troca de papel.
     */
    public static function registrable(): self
    {
        return self::Aluno;
    }
}
