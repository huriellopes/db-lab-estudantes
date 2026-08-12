<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

/** Um schema junto com os dados de quem é dono — usado na listagem do admin. */
final readonly class SchemaWithOwner
{
    public function __construct(
        public int $id,
        public string $dbName,
        public DateTimeImmutable $createdAt,
        public int $ownerId,
        public string $ownerName,
        public string $ownerEmail,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            dbName: (string) $row['db_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            ownerId: (int) $row['owner_id'],
            ownerName: (string) $row['owner_name'],
            ownerEmail: (string) $row['owner_email'],
        );
    }
}
