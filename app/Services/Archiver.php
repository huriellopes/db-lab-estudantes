<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\DeletedModel;
use App\Models\RememberToken;
use App\Models\SavedQuery;
use App\Support\ArchiveGraph;
use App\Support\ArchiveRestoreChecks;
use App\Support\ErrorLogger;
use App\Support\ProgrammableObjects;
use PDO;
use Throwable;

/**
 * Único caminho pra "excluir" dado de negócio: copia pra deleted_models em lote (item + o que
 * depende dele), põe schemas em quarentena, bloqueia a conta MySQL de usuário e só então apaga
 * as linhas originais. restore() faz o caminho inverso; purge() é a exclusão de verdade.
 *
 * DDL do MySQL (RENAME TABLE, ALTER USER, DROP DATABASE) faz commit implícito e não volta com
 * rollBack(), então cada passo de DDL já feito entra numa lista de desfazer, executada se algo
 * falhar depois — tudo-ou-nada na prática.
 */
final class Archiver
{
    public const ORIGIN_APP = 'app';
    /** Registro de schema cujo database alguém apagou por fora (console, phpMyAdmin, SGBD). */
    public const ORIGIN_OUTSIDE = 'outside';

    /**
     * @param array<string, mixed> $auditMeta
     * @param ?array{id: ?int, name: string} $actor Padrão: quem está logado.
     */
    public static function archive(string $model, int $id, string $auditAction, array $auditMeta = [], string $origin = self::ORIGIN_APP, ?array $actor = null): string
    {
        $pdo = Database::connection();
        $actor ??= self::currentActor();

        $root = self::fetchRow($pdo, $model, $id) ?? throw new ArchiveException('Registro não encontrado.');
        $items = [['model' => $model, 'row' => $root, 'is_root' => true]];
        self::collectChildren($pdo, $model, $id, $items);

        $batchId = self::uuid();
        $undo = [];
        $metaById = [];

        try {
            foreach ($items as $i => $item) {
                if ($item['model'] !== 'schema') {
                    continue;
                }
                if ($origin === self::ORIGIN_OUTSIDE) {
                    $metaById[$i] = ['removed_outside' => true];
                    continue;
                }
                try {
                    $moved = SchemaQuarantine::move((string) $item['row']['db_name'], (int) $item['row']['id']);
                } catch (ArchiveException $e) {
                    // Triggers já removidos antes do RENAME que falhou: viram consulta salva do dono, nada se perde.
                    if ($e->orphanedObjects !== []) {
                        self::saveObjectsScript((int) $item['row']['user_id'], (string) $item['row']['db_name'], $e->orphanedObjects);
                    }
                    throw $e;
                }
                $metaById[$i] = $moved;
                $owner = self::ownerLogin($pdo, (int) $item['row']['user_id'], $items);
                $undo[] = static fn () => SchemaQuarantine::restore((string) $item['row']['db_name'], $moved['quarantine'], $owner);
            }

            if ($model === 'user') {
                SchemaProvisioner::lockMysqlAccount((string) $root['mysql_login']);
                $undo[] = static fn () => SchemaProvisioner::unlockMysqlAccount((string) $root['mysql_login']);
            }

            $pdo->beginTransaction();
            foreach ($items as $i => $item) {
                DeletedModel::insert($batchId, $item['is_root'], $item['model'], (int) $item['row']['id'], self::labelFor($pdo, $item['model'], $item['row']), $item['row'], $metaById[$i] ?? [], $actor);
            }
            // Filhos de usuário saem pela FK ON DELETE CASCADE (já estão arquivados acima).
            $pdo->prepare('DELETE FROM ' . ArchiveGraph::table($model) . ' WHERE id = ?')->execute([$id]);
            if ($model === 'user') {
                // Operacional, sem FK: não vai pro arquivo (ver Global Constraints da spec).
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::runUndo($undo);
            AuditLog::record('archive.failed', $model, $id, ['batch' => $batchId, 'erro' => $e->getMessage()]);
            throw $e instanceof ArchiveException ? $e : new ArchiveException('Não foi possível excluir agora — nada foi alterado.', [], $e);
        }

        if ($model === 'user') {
            RememberToken::revokeAllFor($id);
        }

        AuditLog::record($auditAction, $model, $id, ['batch' => $batchId, 'itens' => count($items)] + $auditMeta);

        return $batchId;
    }

    /** @return list<string> Mensagens do que impede restaurar (vazio = pode restaurar). */
    public static function conflicts(string $batchId): array
    {
        $pdo = Database::connection();
        $messages = [];
        foreach (ArchiveRestoreChecks::for(DeletedModel::itemsOfBatch($batchId)) as $check) {
            $stmt = $pdo->prepare($check['sql']);
            $stmt->execute($check['params']);
            $found = $stmt->fetch() !== false;
            if ($found !== ($check['expect'] === 'present')) {
                $messages[] = $check['message'];
            }
        }

        return $messages;
    }

    public static function restore(string $batchId): void
    {
        $pdo = Database::connection();
        $items = DeletedModel::itemsOfBatch($batchId);
        if ($items === []) {
            throw new ArchiveException('Lote não encontrado no arquivo.');
        }
        $root = $items[0];

        foreach ($items as $item) {
            if (($item['meta']['removed_outside'] ?? false) === true) {
                throw new ArchiveException('Este schema foi removido fora da plataforma: não há dados para restaurar. Só é possível excluir definitivamente.');
            }
        }

        $conflicts = self::conflicts($batchId);
        if ($conflicts !== []) {
            AuditLog::record('archive.restore_failed', $root['model'], $root['model_id'], ['batch' => $batchId, 'motivo' => implode(' ', $conflicts)]);
            throw new ArchiveException('Não dá para restaurar: ' . implode(' ', $conflicts));
        }

        $undo = [];
        try {
            $pdo->beginTransaction();
            foreach ($items as $item) {
                self::insertRow($pdo, ArchiveGraph::table($item['model']), $item['values']);
            }
            $pdo->commit();
            $undo[] = static function () use ($pdo, $items): void {
                foreach (array_reverse($items) as $item) {
                    $pdo->prepare('DELETE FROM ' . ArchiveGraph::table($item['model']) . ' WHERE id = ?')->execute([$item['model_id']]);
                }
            };

            foreach ($items as $item) {
                if ($item['model'] !== 'schema') {
                    continue;
                }
                $login = self::currentLogin($pdo, (int) $item['values']['user_id']);
                SchemaQuarantine::restore((string) $item['values']['db_name'], (string) $item['meta']['quarantine'], $login);
                $undo[] = static fn () => SchemaQuarantine::move((string) $item['values']['db_name'], $item['model_id']);
            }

            if ($root['model'] === 'user') {
                SchemaProvisioner::unlockMysqlAccount((string) $root['values']['mysql_login']);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::runUndo($undo);
            AuditLog::record('archive.restore_failed', $root['model'], $root['model_id'], ['batch' => $batchId, 'motivo' => $e->getMessage()]);
            throw new ArchiveException('Não foi possível restaurar agora — nada foi alterado.', [], $e);
        }

        foreach ($items as $item) {
            if ($item['model'] === 'schema' && ($item['meta']['objects'] ?? []) !== []) {
                self::saveObjectsScript((int) $item['values']['user_id'], (string) $item['values']['db_name'], $item['meta']['objects']);
            }
        }

        DeletedModel::deleteBatch($batchId);
        AuditLog::record('archive.restored', $root['model'], $root['model_id'], ['batch' => $batchId, 'itens' => count($items), 'rotulo' => $root['label']]);
    }

    public static function purge(string $batchId): void
    {
        $items = DeletedModel::itemsOfBatch($batchId);
        if ($items === []) {
            throw new ArchiveException('Lote não encontrado no arquivo.');
        }
        $root = $items[0];

        foreach ($items as $item) {
            if ($item['model'] === 'schema' && isset($item['meta']['quarantine'])) {
                SchemaQuarantine::purge((string) $item['meta']['quarantine']);
            }
        }
        if ($root['model'] === 'user') {
            SchemaProvisioner::dropMysqlAccount((string) $root['values']['mysql_login']);
        }

        DeletedModel::deleteBatch($batchId);
        AuditLog::record('archive.purged', $root['model'], $root['model_id'], ['batch' => $batchId, 'itens' => count($items), 'rotulo' => $root['label']]);
    }

    /**
     * Filhos em profundidade, pai antes dos filhos (instituição → turma → vínculo da turma): é a
     * ordem que a restauração precisa pra respeitar as FKs.
     *
     * @param list<array{model: string, row: array<string, mixed>, is_root: bool}> $items
     */
    private static function collectChildren(PDO $pdo, string $model, int $id, array &$items): void
    {
        foreach (ArchiveGraph::children($model) as $childModel => $fk) {
            $stmt = $pdo->prepare('SELECT * FROM ' . ArchiveGraph::table($childModel) . " WHERE {$fk} = ? ORDER BY id");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['model' => $childModel, 'row' => $row, 'is_root' => false];
                self::collectChildren($pdo, $childModel, (int) $row['id'], $items);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function labelFor(PDO $pdo, string $model, array $row): string
    {
        $target = match ($model) {
            'institution_member' => ['institutions', 'institution_id'],
            'class_member' => ['classes', 'class_id'],
            default => null,
        };
        if ($target === null) {
            return ArchiveGraph::label($model, $row);
        }
        $stmt = $pdo->prepare("SELECT u.name, t.name FROM users u, {$target[0]} t WHERE u.id = ? AND t.id = ?");
        $stmt->execute([(int) $row['user_id'], (int) $row[$target[1]]]);
        $names = $stmt->fetch(PDO::FETCH_NUM);

        return $names === false
            ? ArchiveGraph::label($model, $row)
            : mb_substr("{$names[0]} → {$names[1]} ({$row['role']})", 0, 200);
    }

    /** @return ?array<string, mixed> */
    private static function fetchRow(PDO $pdo, string $model, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . ArchiveGraph::table($model) . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Reinsere a linha arquivada. Colunas geradas (ex.: institution_members.student_user_id —
     * o MySQL recusa valor nelas) e colunas que deixaram de existir desde a exclusão (ex.:
     * users.deleted_at, removida na migração da lixeira antiga) são ignoradas.
     *
     * @param array<string, mixed> $values
     */
    private static function insertRow(PDO $pdo, string $table, array $values): void
    {
        $existing = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%'",
        );
        $existing->execute([$table]);
        $values = array_intersect_key($values, array_flip($existing->fetchAll(PDO::FETCH_COLUMN)));
        $columns = array_keys($values);
        foreach ($columns as $column) {
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new ArchiveException("Coluna inesperada no arquivo: {$column}");
            }
        }
        $pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
        )->execute(array_values($values));
    }

    /** @param list<array{model: string, row: array<string, mixed>, is_root: bool}> $items */
    private static function ownerLogin(PDO $pdo, int $userId, array $items): string
    {
        foreach ($items as $item) {
            if ($item['model'] === 'user' && (int) $item['row']['id'] === $userId) {
                return (string) $item['row']['mysql_login'];
            }
        }

        return self::currentLogin($pdo, $userId);
    }

    private static function currentLogin(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT mysql_login FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: throw new ArchiveException("Dono do schema (usuário #{$userId}) não encontrado."));
    }

    /** @param list<string> $sqls */
    private static function saveObjectsScript(int $userId, string $dbName, array $sqls): void
    {
        try {
            SavedQuery::create($userId, mb_substr("Restaurar objetos de {$dbName}", 0, 80), $dbName, ProgrammableObjects::restoreScript($sqls));
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'warning');
        }
    }

    /** @param list<callable(): mixed> $undo */
    private static function runUndo(array $undo): void
    {
        foreach (array_reverse($undo) as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                // Desfazer não pode esconder o erro original; fica no log pro admin.
                ErrorLogger::exception($e, 'critical');
            }
        }
    }

    /** @return ?array{id: ?int, name: string} */
    private static function currentActor(): ?array
    {
        $user = Auth::user();

        return $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
