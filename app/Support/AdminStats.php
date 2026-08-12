<?php

declare(strict_types=1);

namespace App\Support;

final readonly class AdminStats
{
    public function __construct(
        public int $alunos,
        public int $professores,
        public int $admins,
        public int $schemas,
    ) {
    }
}
