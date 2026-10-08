<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class Institution
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $inviteCode,
        public DateTimeImmutable $createdAt,
        public int $professors = 0,
        public int $students = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            inviteCode: $row['invite_code'] !== null ? (string) $row['invite_code'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            professors: (int) ($row['professors'] ?? 0),
            students: (int) ($row['students'] ?? 0),
        );
    }
}
