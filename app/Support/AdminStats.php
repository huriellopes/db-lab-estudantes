<?php

declare(strict_types=1);

namespace App\Support;

/** Números do painel do admin — montado por App\Models\AdminMetrics::collect(). */
final readonly class AdminStats
{
    /**
     * @param list<array{date: string, total: int}> $signupsByDay Cadastros por dia, últimos 14 dias (dias sem cadastro incluídos, com 0).
     * @param list<array{dbName: string, ownerName: string, bytes: int}> $largestSchemas
     */
    public function __construct(
        public int $alunos,
        public int $professores,
        public int $admins,
        public int $schemas,
        public int $inactiveUsers = 0,
        public int $trashedUsers = 0,
        public int $activeLast7Days = 0,
        public int $activeLast30Days = 0,
        public int $neverLoggedIn = 0,
        public int $savedQueries = 0,
        public int $erDiagrams = 0,
        public int $schemasTotalBytes = 0,
        public array $signupsByDay = [],
        public array $largestSchemas = [],
        public int $errorsLast24h = 0,
    ) {
    }

    public function totalUsers(): int
    {
        return $this->alunos + $this->professores + $this->admins;
    }

    /** Maior valor diário de cadastros — escala das barras do mini-gráfico. */
    public function maxSignupsPerDay(): int
    {
        return max(1, ...array_column($this->signupsByDay, 'total'));
    }
}
