<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Support\Role;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public string $mysqlLogin,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row Linha crua vinda de um fetch() do PDO. */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: Role::from((string) $row['role']),
            mysqlLogin: (string) $row['mysql_login'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
