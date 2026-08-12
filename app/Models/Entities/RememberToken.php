<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class RememberToken
{
    public function __construct(
        public int $id,
        public int $userId,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
        );
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }
}
