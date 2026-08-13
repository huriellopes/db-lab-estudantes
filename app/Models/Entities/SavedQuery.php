<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class SavedQuery
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        public ?string $schemaName,
        public string $sqlText,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            title: (string) $row['title'],
            schemaName: $row['schema_name'] !== null ? (string) $row['schema_name'] : null,
            sqlText: (string) $row['sql_text'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * Forma que o Alpine `sqlConsole` (resources/js/app.js) espera — devolvida pela ação
     * que carrega o dashboard e por toda ação que salva/exclui uma consulta.
     *
     * @return array{id: int, title: string, schema: string|null, sql: string}
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'schema' => $this->schemaName,
            'sql' => $this->sqlText,
        ];
    }
}
