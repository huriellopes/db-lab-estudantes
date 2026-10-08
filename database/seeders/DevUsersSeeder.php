<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;
use App\Models\User;
use App\Services\UserManager;
use App\Support\Role;

/**
 * Contas prontas pra desenvolvimento local, uma por papel, todas com a senha password123
 * (ver USERS; logins MySQL com prefixo "dev" — "admin" é reservado, ver MysqlIdentifier).
 * A mesma senha abre a conta MySQL, e a porta do MySQL é pública em produção (SECURITY.md,
 * "MySQL público"), por isso só roda com APP_ENV=local explícito: sem a variável, ou com
 * qualquer outro valor, não cria nada. Os seeders vão na imagem de produção (autoload
 * normal), então essa trava é a única barreira.
 *
 * Idempotente: conta cujo e-mail já existe não é tocada.
 */
final class DevUsersSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    /** @var list<array{name: string, email: string, username: string, role: Role}> */
    public const USERS = [
        ['name' => 'Admin Dev', 'email' => 'admin@dblab.local', 'username' => 'devadmin', 'role' => Role::Admin],
        ['name' => 'Professor Dev', 'email' => 'professor@dblab.local', 'username' => 'devprofessor', 'role' => Role::Professor],
        ['name' => 'Aluno Dev', 'email' => 'aluno@dblab.local', 'username' => 'devaluno', 'role' => Role::Aluno],
    ];

    private const LOCAL_ENVS = ['local', 'development', 'dev'];

    public static function isLocal(?string $env): bool
    {
        return in_array(strtolower(trim((string) $env)), self::LOCAL_ENVS, true);
    }

    public function run(): void
    {
        $env = Config::get('APP_ENV');
        if (!self::isLocal($env)) {
            $this->info('DevUsersSeeder pulado: só roda com APP_ENV=local (atual: ' . ($env ?? 'não definido') . ').');

            return;
        }

        foreach (self::USERS as $user) {
            $label = "{$user['role']->label()} de dev";

            if (User::findByEmail($user['email']) !== null) {
                $this->info("{$label} já existe: {$user['email']} — nada alterado.");

                continue;
            }

            UserManager::provisionNewUser($user['name'], $user['email'], $user['username'], self::PASSWORD, $user['role']);
            $this->info("{$label} criado: {$user['email']} / " . self::PASSWORD . " (login MySQL: {$user['username']}).");
        }
    }
}
