<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Ponto de entrada único do `db:seed` — igual ao DatabaseSeeder do Laravel. Em produção, a
 * promoção a admin é o comando explícito `php bin/console.php user:promote-admin <email>`
 * (ver App\Core\Console::promoteAdmin); o DevUsersSeeder só age com APP_ENV=local.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DevUsersSeeder::class);
    }
}
