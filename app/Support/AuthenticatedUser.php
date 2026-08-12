<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Entities\User;

/**
 * Recorte tipado do usuário guardado na sessão — de propósito sem password_hash, para
 * não deixar nem o hash da senha sentado em disco no arquivo de sessão do PHP.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public Role $role,
        public string $mysqlLogin,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self($user->id, $user->name, $user->email, $user->role, $user->mysqlLogin);
    }

    /** "Huriel Correia Lopes" -> "Huriel Lopes" — usado na navbar, que tem pouco espaço. */
    public function shortName(): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($this->name)) ?: []));

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => $parts[0] . ' ' . $parts[array_key_last($parts)],
        };
    }
}
