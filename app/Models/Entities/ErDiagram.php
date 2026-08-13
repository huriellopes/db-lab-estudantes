<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class ErDiagram
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        /** JSON cru (ver App\Models\ErDiagram) — quem decodifica é o controller, na hora de devolver como resposta. */
        public string $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            title: (string) $row['title'],
            data: (string) $row['data'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
