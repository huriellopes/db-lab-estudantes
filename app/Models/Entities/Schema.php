<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class Schema
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $dbName,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            dbName: (string) $row['db_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
