<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

/** Turma ("Class" é palavra reservada em PHP). */
final readonly class SchoolClass
{
    public function __construct(
        public int $id,
        public int $institutionId,
        public string $institutionName,
        public string $name,
        public DateTimeImmutable $createdAt,
        public int $students = 0,
        public int $professors = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            institutionId: (int) $row['institution_id'],
            institutionName: (string) $row['institution_name'],
            name: (string) $row['name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            students: (int) ($row['students'] ?? 0),
            professors: (int) ($row['professors'] ?? 0),
        );
    }
}
