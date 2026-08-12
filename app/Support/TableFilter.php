<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Busca + ordenação + paginação em memória — as listagens do lab (usuários, schemas) são
 * pequenas o bastante pra não valer a complexidade de LIMIT/OFFSET e WHERE dinâmico no SQL.
 */
final class TableFilter
{
    /**
     * @template T
     * @param list<T> $items
     * @param callable(T): string $searchText Texto pesquisável de cada item (busca por substring, case-insensitive).
     * @param array<string, callable(T): (int|string)> $sortAccessors Valor comparável por chave de ordenação aceita.
     */
    public static function paginate(
        array $items,
        TableQuery $query,
        callable $searchText,
        array $sortAccessors,
        int $perPage,
    ): Paginator {
        $filtered = $query->search === ''
            ? $items
            : array_values(array_filter(
                $items,
                static fn (mixed $item): bool => str_contains(
                    mb_strtolower($searchText($item)),
                    mb_strtolower($query->search),
                ),
            ));

        if ($query->sort !== null && isset($sortAccessors[$query->sort])) {
            $accessor = $sortAccessors[$query->sort];
            usort($filtered, static fn (mixed $a, mixed $b): int => $accessor($a) <=> $accessor($b));
            if ($query->direction === 'desc') {
                $filtered = array_reverse($filtered);
            }
        }

        $total = count($filtered);
        $page = min($query->page, max(1, (int) ceil($total / $perPage)));

        return new Paginator(
            items: array_slice($filtered, ($page - 1) * $perPage, $perPage),
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }
}
