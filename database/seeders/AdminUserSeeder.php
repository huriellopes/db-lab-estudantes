<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;
use App\Models\User;
use App\Support\Role;

/** Promove a ADMIN_EMAIL (se existir e ainda não for admin) a super admin. Idempotente. */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = Config::get('ADMIN_EMAIL');

        if ($email === null) {
            fwrite(STDOUT, "AdminUserSeeder: variável ADMIN_EMAIL não definida — nada a fazer.\n");

            return;
        }

        $user = User::findByEmail($email);

        if ($user === null) {
            fwrite(STDOUT, "AdminUserSeeder: nenhuma conta com o e-mail \"{$email}\" ainda — cadastre-se primeiro.\n");

            return;
        }

        if ($user->role === Role::Admin) {
            fwrite(STDOUT, "AdminUserSeeder: \"{$email}\" já é admin.\n");

            return;
        }

        User::updateRole($user->id, Role::Admin);
        fwrite(STDOUT, "AdminUserSeeder: \"{$email}\" promovido a admin.\n");
    }
}
