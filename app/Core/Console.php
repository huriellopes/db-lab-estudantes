<?php

declare(strict_types=1);

namespace App\Core;

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

    private function usage(): int
    {
        $this->line('Uso: php bin/console.php <migrate|migrate:rollback|migrate:status|db:seed>');

        return 1;
    }

    private function line(string $message, bool $isError = false): void
    {
        fwrite($isError ? STDERR : STDOUT, $message . PHP_EOL);
    }
}
