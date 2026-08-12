<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/** Ponto de entrada único do `db:seed` — igual ao DatabaseSeeder do Laravel. */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        (new AdminUserSeeder())->run();
    }
}
