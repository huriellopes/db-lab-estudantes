<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Archiver;
use App\Services\SchemaQuarantine;
use App\Support\ByteSize;
use App\Support\RetentionPolicy;
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
            'audit:prune' => $this->pruneAudit($argv[2] ?? null),
            'archive:purge-expired' => $this->purgeExpired(in_array('--aplicar', $argv, true)),
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

    /** Apaga registros de auditoria mais velhos que N dias (padrão 180). Uso: audit:prune [--days=180]. */
    private function pruneAudit(?string $option): int
    {
        $days = 180;
        if ($option !== null) {
            if (preg_match('/^--days=(\d+)$/', $option, $m) !== 1 || (int) $m[1] < 1) {
                $this->line('Uso: php bin/console.php audit:prune [--days=180]', true);

                return 1;
            }
            $days = (int) $m[1];
        }

        $deleted = AuditLog::prune($days);
        $this->line("{$deleted} registro(s) de auditoria com mais de {$days} dias removido(s).");

        return 0;
    }

    /**
     * Expurgo automático de Dados excluídos (ARCHIVE_RETENTION_DAYS; 0/vazio = desligado). Sem
     * --aplicar só mostra o que sairia. Roda sozinho uma vez por dia (docker/archive-purge-loop.sh).
     */
    private function purgeExpired(bool $apply): int
    {
        $days = RetentionPolicy::days(Config::get('ARCHIVE_RETENTION_DAYS'));
        if ($days === 0) {
            $this->line('Expurgo automático desligado (ARCHIVE_RETENTION_DAYS vazio ou 0). Nada a fazer.');

            return 0;
        }

        $expired = Archiver::purgeExpired($days, apply: false);
        $quarantines = [];
        foreach ($expired as $batch) {
            foreach (\App\Models\DeletedModel::itemsOfBatch($batch['batch_id']) as $item) {
                if (isset($item['meta']['quarantine'])) {
                    $quarantines[] = (string) $item['meta']['quarantine'];
                }
            }
        }
        $bytes = array_sum(SchemaQuarantine::sizeBytes($quarantines));

        $this->line(($apply ? 'Expurgando' : 'Simulação: seriam expurgados') . ' ' . count($expired) . " lote(s) com mais de {$days} dias (libera ~" . ByteSize::format($bytes) . ' de schemas em quarentena).');
        if (!$apply) {
            foreach ($expired as $batch) {
                $this->line("  - {$batch['label']} ({$batch['model']}, excluído em {$batch['deleted_at']})");
            }

            return 0;
        }

        $failed = 0;
        foreach (Archiver::purgeExpired($days, apply: true) as $batch) {
            $ok = !isset($batch['error']);
            $failed += $ok ? 0 : 1;
            $this->line(($ok ? '  ✓ ' : '  ✗ ') . $batch['label'] . ($ok ? '' : ": {$batch['error']}"), !$ok);
        }

        return $failed > 0 ? 1 : 0;
    }

    private function usage(): int
    {
        $this->line('Uso: php bin/console.php <migrate|migrate:rollback|migrate:status|db:seed|user:promote-admin <email>|audit:prune [--days=N]|archive:purge-expired [--aplicar]>');

        return 1;
    }

    private function line(string $message, bool $isError = false): void
    {
        fwrite($isError ? STDERR : STDOUT, $message . PHP_EOL);
    }
}
