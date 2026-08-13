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

    /**
     * Forma resumida (sem o `data` inteiro) usada nas listas — a página do laboratório
     * (App\Actions\ErDiagram\ShowErDiagramLabAction) e a resposta de toda ação que
     * salva/exclui um diagrama devolvem a lista atualizada nesse formato.
     *
     * @return array{id: int, title: string, updatedAt: string}
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'updatedAt' => $this->updatedAt->format('d/m/Y H:i'),
        ];
    }
}
