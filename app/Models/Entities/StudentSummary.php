<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

/** Um aluno junto com a contagem de schemas — usado na listagem do professor/admin. */
final readonly class StudentSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public int $schemasCount,
    ) {
    }

    public static function fromUser(User $user, int $schemasCount): self
    {
        return new self($user->id, $user->name, $user->email, $user->active, $user->createdAt, $schemasCount);
    }
}
