<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Estado de busca/ordenação/paginação de uma listagem, lido da querystring (?q=&sort=&dir=&page=).
 * Sem JS: os links de ordenação/paginação e o form de busca (method="get") funcionam só com
 * navegação normal — o mesmo princípio de "funciona sem JS" já usado em ajaxForm.
 */
final readonly class TableQuery
{
    private function __construct(
        public string $search,
        public ?string $sort,
        public string $direction,
        public int $page,
    ) {
    }

    /**
     * @param array<string, mixed> $params Normalmente $_GET.
     * @param list<string> $allowedSorts Chaves de ordenação aceitas — qualquer outra vira null (ignora).
     */
    public static function fromParams(array $params, array $allowedSorts, ?string $defaultSort = null): self
    {
        $sortParam = isset($params['sort']) ? (string) $params['sort'] : null;
        $sort = $sortParam !== null && in_array($sortParam, $allowedSorts, true) ? $sortParam : $defaultSort;

        return new self(
            search: trim((string) ($params['q'] ?? '')),
            sort: $sort,
            direction: ($params['dir'] ?? '') === 'desc' ? 'desc' : 'asc',
            page: max(1, (int) ($params['page'] ?? 1)),
        );
    }

    /** Direção pra qual um clique no cabeçalho da coluna $key deveria levar. */
    public function nextDirectionFor(string $key): string
    {
        return $this->sort === $key && $this->direction === 'asc' ? 'desc' : 'asc';
    }

    public function isSortedBy(string $key): bool
    {
        return $this->sort === $key;
    }

    /**
     * Querystring com o estado atual + overrides pontuais — usado nos links de busca,
     * ordenação e paginação pra preservar o resto do estado. page=1 e valores vazios
     * somem da URL (mantém limpa quando estão no padrão).
     *
     * @param array<string, mixed> $overrides
     */
    public function urlWith(array $overrides): string
    {
        $params = [
            'q' => $overrides['q'] ?? $this->search,
            'sort' => $overrides['sort'] ?? $this->sort,
            'dir' => $overrides['dir'] ?? $this->direction,
            'page' => $overrides['page'] ?? $this->page,
        ];

        $params = array_filter($params, static function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return $value !== 1 && $value !== 'asc';
        });

        return $params === [] ? '' : ('?' . http_build_query($params));
    }
}
