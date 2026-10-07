<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Ponto de entrada único do `db:seed` — igual ao DatabaseSeeder do Laravel. Hoje sem
 * seeders padrão: a promoção a admin, que era o AdminUserSeeder, virou o comando explícito
 * `php bin/console.php user:promote-admin <email>` (ver App\Core\Console::promoteAdmin).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
    }
}
