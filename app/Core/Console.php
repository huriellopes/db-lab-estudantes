<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Support\Role;
use Database\Seeders\DatabaseSeeder;
use Throwable;

/** Dispatcher de linha de comando simples, no estilo do `php artisan` — ver bin/console.php. */
final class Console
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        return match ($argv[1] ?? null) {
            'migrate' => $this->migrate(),
            'migrate:rollback' => $this->rollback(),
            'migrate:status' => $this->status(),
            'db:seed' => $this->seed(),
            'user:promote-admin' => $this->promoteAdmin($argv[2] ?? null),
            default => $this->usage(),
        };
    }

    private function migrate(): int
    {
        $applied = $this->migrator->migrate();

        if ($applied === []) {
            $this->line('Nada para migrar — tudo já está em dia.');

            return 0;
        }

        foreach ($applied as $name) {
            $this->line("Migrated: {$name}");
        }

        return 0;
    }

    private function rollback(): int
    {
        $rolledBack = $this->migrator->rollback();

        if ($rolledBack === []) {
            $this->line('Nada para reverter.');

            return 0;
        }

        foreach ($rolledBack as $name) {
            $this->line("Rolled back: {$name}");
        }

        return 0;
    }

    private function status(): int
    {
        foreach ($this->migrator->status() as $name => $ran) {
            $this->line(($ran ? '[x] ' : '[ ] ') . $name);
        }

        return 0;
    }

    private function seed(): int
    {
        if (!class_exists(DatabaseSeeder::class)) {
            $this->line('Seeders não encontrados — rode "composer install" (sem --no-dev) para ter as dependências de desenvolvimento.', true);

            return 1;
        }

        try {
            (new DatabaseSeeder())->run();
        } catch (Throwable $e) {
            $this->line('Erro ao rodar os seeders: ' . $e->getMessage(), true);

            return 1;
        }

        $this->line('Seed concluído.');

        return 0;
    }

    /**
     * Promove uma conta JÁ EXISTENTE a admin — explicitamente, conferindo de quem é a conta.
     *
     * Substitui o antigo AdminUserSeeder, que promovia sozinho quem tivesse o e-mail de
     * ADMIN_EMAIL. Como o cadastro não verifica e-mail, quem se cadastrasse primeiro com
     * aquele endereço virava admin no próximo `db:seed`, sem ninguém perceber. Aqui o
     * comando mostra os dados da conta e exige digitar o username dela: quem roda o
     * comando percebe na hora se a conta com aquele e-mail não é a sua.
     */
    private function promoteAdmin(?string $email): int
    {
        if ($email === null || $email === '') {
            $this->line('Uso: php bin/console.php user:promote-admin <email>', true);

            return 1;
        }

        $user = User::findByEmail($email);
        if ($user === null) {
            $this->line("Nenhuma conta (não excluída) com o e-mail \"{$email}\". Cadastre-se primeiro.", true);

            return 1;
        }
        if ($user->role === Role::Admin) {
            $this->line("\"{$email}\" já é admin.");

            return 0;
        }

        $this->line('Conta encontrada:');
        $this->line("  Nome:         {$user->name}");
        $this->line("  Username:     {$user->mysqlLogin}");
        $this->line("  Papel atual:  {$user->role->label()}");
        $this->line('  Ativa:        ' . ($user->active ? 'sim' : 'não'));
        $this->line('  Criada em:    ' . $user->createdAt->format('d/m/Y H:i'));
        $this->line('  Último login: ' . ($user->lastLoginAt?->format('d/m/Y H:i') ?? 'nunca'));
        fwrite(STDOUT, 'Se essa conta é mesmo sua, digite o username dela pra confirmar: ');

        $typed = trim((string) fgets(STDIN));
        if ($typed !== $user->mysqlLogin) {
            $this->line('Username não confere — nada foi alterado.', true);

            return 1;
        }

        User::updateRole($user->id, Role::Admin);
        $this->line("\"{$email}\" promovido a admin.");

        return 0;
    }

    private function usage(): int
    {
        $this->line('Uso: php bin/console.php <migrate|migrate:rollback|migrate:status|db:seed|user:promote-admin <email>>');

        return 1;
    }

    private function line(string $message, bool $isError = false): void
    {
        fwrite($isError ? STDERR : STDOUT, $message . PHP_EOL);
    }
}
