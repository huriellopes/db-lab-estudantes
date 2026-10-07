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
        public string $schemaPrefix,
        public bool $active,
        public int $sessionVersion,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deletedAt,
        public ?DateTimeImmutable $lastLoginAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
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
            // NULL só numa linha ainda "pending" (cadastro no meio) — ver UserManager::provisionNewUser.
            schemaPrefix: (string) ($row['schema_prefix'] ?? $row['mysql_login']),
            active: (bool) $row['active'],
            sessionVersion: (int) ($row['session_version'] ?? 0),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            deletedAt: $row['deleted_at'] !== null ? new DateTimeImmutable((string) $row['deleted_at']) : null,
            lastLoginAt: $row['last_login_at'] !== null ? new DateTimeImmutable((string) $row['last_login_at']) : null,
        );
    }
}
