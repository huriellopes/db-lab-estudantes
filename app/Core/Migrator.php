<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Runner de migrations no estilo do Laravel: cada arquivo em database/migrations/ devolve
 * uma classe anônima que estende Migration; uma tabela "migrations" registra o que já
 * rodou (com batch, pra dar pra reverter o último lote com migrate:rollback).
 */
final class Migrator
{
    private const TABLE = 'migrations';
    private const PATH = __DIR__ . '/../../database/migrations';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> nomes das migrations aplicadas nesta chamada */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $ran = $this->ranMigrations();
        $batch = $this->nextBatch();
        $applied = [];

        foreach ($this->allMigrationFiles() as $file) {
            $name = $this->migrationName($file);
            if (in_array($name, $ran, true)) {
                continue;
            }

            $this->loadMigration($file)->up($this->pdo);

            $stmt = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (migration, batch) VALUES (?, ?)');
            $stmt->execute([$name, $batch]);
            $applied[] = $name;
        }

        return $applied;
    }

    /** @return list<string> nomes das migrations revertidas */
    public function rollback(): array
    {
        $this->ensureMigrationsTable();

        $lastBatch = $this->pdo->query('SELECT MAX(batch) FROM ' . self::TABLE)->fetchColumn();
        if ($lastBatch === null || $lastBatch === false) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT migration FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC');
        $stmt->execute([(int) $lastBatch]);
        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $rolledBack = [];
        foreach ($names as $name) {
            $file = self::PATH . "/{$name}.php";
            if (!is_file($file)) {
                continue;
            }

            $this->loadMigration($file)->down($this->pdo);

            $delete = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE migration = ?');
            $delete->execute([$name]);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /** @return array<string, bool> nome da migration => se já rodou */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $ran = $this->ranMigrations();
        $status = [];
        foreach ($this->allMigrationFiles() as $file) {
            $name = $this->migrationName($file);
            $status[$name] = in_array($name, $ran, true);
        }

        return $status;
    }

    /**
     * Marca migrations como já aplicadas sem rodar up() — usado uma única vez ao
     * introduzir este sistema num banco que já tinha esse schema criado "na mão".
     */
    public function markAsRan(string ...$names): void
    {
        $this->ensureMigrationsTable();
        $ran = $this->ranMigrations();
        $batch = $this->nextBatch();

        foreach ($names as $name) {
            if (in_array($name, $ran, true)) {
                continue;
            }
            $stmt = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (migration, batch) VALUES (?, ?)');
            $stmt->execute([$name, $batch]);
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        /** @var list<string> */
        return $this->pdo->query('SELECT migration FROM ' . self::TABLE)->fetchAll(PDO::FETCH_COLUMN);
    }

    private function nextBatch(): int
    {
        $max = $this->pdo->query('SELECT MAX(batch) FROM ' . self::TABLE)->fetchColumn();

        return ($max !== null && $max !== false) ? ((int) $max + 1) : 1;
    }

    /** @return list<string> caminhos completos, em ordem alfabética (= cronológica pelo prefixo) */
    private function allMigrationFiles(): array
    {
        $files = glob(self::PATH . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    private function migrationName(string $file): string
    {
        return basename($file, '.php');
    }

    private function loadMigration(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration inválida (não retornou uma App\\Core\\Migration): {$file}");
        }

        return $migration;
    }
}
