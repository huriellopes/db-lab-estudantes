<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Tabela deleted_models — o arquivo de dados excluídos. Só App\Services\Archiver escreve
 * aqui; a tela /admin/excluidos só lê (ver paginateBatches, Task 10).
 */
final class DeletedModel
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $meta
     * @param ?array{id: ?int, name: string} $actor
     */
    public static function insert(string $batchId, bool $isRoot, string $model, int $modelId, string $label, array $values, array $meta, ?array $actor): void
    {
        Database::connection()->prepare(
            'INSERT INTO deleted_models (batch_id, is_root, model, model_id, label, `values`, meta, deleted_by_id, deleted_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $batchId,
            $isRoot ? 1 : 0,
            $model,
            $modelId,
            $label,
            json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $actor['id'] ?? null,
            $actor['name'] ?? null,
        ]);
    }

    /** @return list<array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, deleted_at: string}> */
    public static function itemsOfBatch(string $batchId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM deleted_models WHERE batch_id = ? ORDER BY is_root DESC, id');
        $stmt->execute([$batchId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    private const RESERVABLE = [
        'email' => ['user', '$.email'],
        'mysql_login' => ['user', '$.mysql_login'],
        'schema_prefix' => ['user', '$.schema_prefix'],
        'db_name' => ['schema', '$.db_name'],
    ];

    /**
     * E-mail/login/prefixo de conta e nome de schema que estão no arquivo continuam "ocupados"
     * até a exclusão definitiva: a conta MySQL bloqueada ainda existe, e liberar o nome faria a
     * restauração (ou o CREATE USER de outra pessoa) bater nele.
     */
    public static function isReserved(string $field, string $value): bool
    {
        [$model, $path] = self::RESERVABLE[$field] ?? throw new \InvalidArgumentException("Campo não reservável: {$field}");
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM deleted_models WHERE model = ? AND JSON_UNQUOTE(JSON_EXTRACT(`values`, ?)) = ? LIMIT 1',
        );
        $stmt->execute([$model, $path, $value]);

        return $stmt->fetch() !== false;
    }

    public static function deleteBatch(string $batchId): void
    {
        Database::connection()->prepare('DELETE FROM deleted_models WHERE batch_id = ?')->execute([$batchId]);
    }

    public static function countBatches(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM deleted_models WHERE is_root = 1')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, deleted_at: string}
     */
    public static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'batch_id' => (string) $row['batch_id'],
            'is_root' => (bool) $row['is_root'],
            'model' => (string) $row['model'],
            'model_id' => (int) $row['model_id'],
            'label' => (string) $row['label'],
            'values' => json_decode((string) $row['values'], true) ?: [],
            'meta' => $row['meta'] !== null ? (json_decode((string) $row['meta'], true) ?: []) : [],
            'deleted_by_name' => $row['deleted_by_name'] !== null ? (string) $row['deleted_by_name'] : null,
            'deleted_at' => (string) $row['deleted_at'],
        ];
    }
}
