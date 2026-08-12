<?php

declare(strict_types=1);

namespace App\Support;

/** Página atual de uma lista, com o total real (pré-paginação) pra montar os controles. */
final readonly class Paginator
{
    /** @param list<mixed> $items Itens já recortados pra página atual. */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage();
    }

    /** Índice (1-based) do primeiro item exibido — pra textos tipo "1–10 de 23". */
    public function from(): int
    {
        return $this->total === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}
