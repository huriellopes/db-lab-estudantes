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
}
