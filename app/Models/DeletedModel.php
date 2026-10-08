<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\SchemaQuarantine;
use App\Support\Paginator;

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

    /** @return list<array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, keep: bool, deleted_at: string}> */
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
        'institution_name' => ['institution', '$.name'],
        'invite_code' => ['institution', '$.invite_code'],
        'class_invite_code' => ['class', '$.invite_code'],
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

    /** "Não apagar" (ou liberar de novo) um lote para o expurgo automático por tempo. */
    public static function setKeep(string $batchId, bool $keep): void
    {
        Database::connection()->prepare('UPDATE deleted_models SET keep = ? WHERE batch_id = ? AND is_root = 1')->execute([$keep ? 1 : 0, $batchId]);
    }

    /**
     * Lotes vencidos para o expurgo: raiz excluída há mais de $days dias e não marcada "não apagar".
     *
     * @return list<array{batch_id: string, model: string, label: string, deleted_at: string}>
     */
    public static function expiredBatches(int $days): array
    {
        if ($days <= 0) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT batch_id, model, label, deleted_at FROM deleted_models
             WHERE is_root = 1 AND keep = 0 AND deleted_at < NOW() - INTERVAL ? DAY ORDER BY deleted_at',
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function deleteBatch(string $batchId): void
    {
        Database::connection()->prepare('DELETE FROM deleted_models WHERE batch_id = ?')->execute([$batchId]);
    }

    public static function paginateBatches(?string $model, string $search, int $days, int $page, int $perPage = 15): Paginator
    {
        $where = ['d.is_root = 1'];
        $args = [];
        if ($model !== null && $model !== '') {
            $where[] = 'd.model = ?';
            $args[] = $model;
        }
        if ($days > 0) {
            $where[] = 'd.deleted_at >= NOW() - INTERVAL ? DAY';
            $args[] = $days;
        }
        if ($search !== '') {
            // Busca no rótulo de qualquer item do lote (ex.: achar um usuário pelo nome de um schema dele).
            $where[] = '(d.label LIKE ? OR EXISTS (SELECT 1 FROM deleted_models x WHERE x.batch_id = d.batch_id AND x.label LIKE ?))';
            array_push($args, "%{$search}%", "%{$search}%");
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM deleted_models d WHERE {$whereSql}");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, min($page, (int) ceil(max($total, 1) / $perPage)));

        $stmt = $pdo->prepare(
            "SELECT d.batch_id FROM deleted_models d WHERE {$whereSql} ORDER BY d.deleted_at DESC, d.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
        );
        $stmt->execute($args);
        $batchIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return new Paginator(self::describeBatches($batchIds), $page, $perPage, $total);
    }

    /**
     * @param list<string> $batchIds
     * @return list<array<string, mixed>>
     */
    private static function describeBatches(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM deleted_models WHERE batch_id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ') ORDER BY is_root DESC, id',
        );
        $stmt->execute($batchIds);

        $byBatch = array_fill_keys($batchIds, []);
        foreach ($stmt->fetchAll() as $row) {
            $byBatch[$row['batch_id']][] = self::hydrate($row);
        }

        $quarantines = [];
        foreach ($byBatch as $items) {
            foreach ($items as $item) {
                if (isset($item['meta']['quarantine'])) {
                    $quarantines[] = (string) $item['meta']['quarantine'];
                }
            }
        }
        $sizes = SchemaQuarantine::sizeBytes($quarantines);

        $batches = [];
        foreach ($byBatch as $batchId => $items) {
            $root = $items[0];
            $related = [];
            $bytes = 0;
            foreach ($items as $item) {
                if (!$item['is_root']) {
                    $related[$item['model']] = ($related[$item['model']] ?? 0) + 1;
                }
                $bytes += $sizes[$item['meta']['quarantine'] ?? ''] ?? 0;
            }
            $batches[] = [
                'batch_id' => $batchId,
                'model' => $root['model'],
                'label' => $root['label'],
                'deleted_by_name' => $root['deleted_by_name'],
                'deleted_at' => $root['deleted_at'],
                'keep' => $root['keep'],
                'removed_outside' => ($root['meta']['removed_outside'] ?? false) === true,
                'related' => $related,
                'quarantine_bytes' => $bytes,
                'items' => array_map(static fn (array $i): array => [
                    'model' => $i['model'],
                    'model_id' => $i['model_id'],
                    'label' => $i['label'],
                    'values' => $i['values'],
                ], $items),
            ];
        }

        return $batches;
    }

    public static function countBatches(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM deleted_models WHERE is_root = 1')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, keep: bool, deleted_at: string}
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
            'keep' => (bool) ($row['keep'] ?? false),
            'deleted_at' => (string) $row['deleted_at'],
        ];
    }
}
