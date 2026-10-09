<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Support\AuditMeta;
use App\Support\ClientIp;
use App\Support\ErrorLogger;
use App\Support\Paginator;
use Throwable;

/**
 * Trilha de auditoria global (tabela audit_logs) — lida em GET /admin/auditoria.
 * record() nunca lança: falhar ao auditar não pode desfazer/derrubar a ação auditada.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed> $meta
     * @param ?array{id: int, name: string} $actor Quem fez — padrão: o usuário logado (passar quando ainda não há sessão, ex.: login).
     */
    public static function record(
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $meta = [],
        ?array $actor = null,
    ): void {
        try {
            $user = Auth::user();
            $actor ??= $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
            $meta = AuditMeta::sanitize($meta);
            // Durante um "entrar como" (Auth::impersonate), toda ação leva junto o admin por trás.
            $impersonator = Auth::impersonator();

            $stmt = Database::connection()->prepare(
                'INSERT INTO audit_logs (user_id, user_name, impersonator_id, impersonator_name, action, target_type, target_id, meta, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([
                $actor['id'] ?? null,
                $actor['name'] ?? null,
                $impersonator['id'] ?? null,
                $impersonator['name'] ?? null,
                $action,
                $targetType,
                $targetId === null ? null : (string) $targetId,
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                ClientIp::resolve($_SERVER),
            ]);
        } catch (Throwable $e) {
            error_log("Falha ao auditar \"{$action}\": {$e->getMessage()}");
            ErrorLogger::exception($e, 'warning');
        }
    }

    /** Busca por ação, nome de quem fez (ou do admin por trás), alvo ou IP; mais recente primeiro. */
    public static function paginate(string $search, ?string $action, int $days, int $page, int $perPage = 25): Paginator
    {
        $where = ['created_at >= NOW() - INTERVAL ? DAY'];
        $args = [$days];
        if ($search !== '') {
            $where[] = '(action LIKE ? OR user_name LIKE ? OR impersonator_name LIKE ? OR target_id LIKE ? OR ip LIKE ? OR meta LIKE ?)';
            array_push($args, ...array_fill(0, 6, '%' . $search . '%'));
        }
        if ($action !== null && $action !== '') {
            $where[] = 'action = ?';
            $args[] = $action;
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$whereSql}");
        $count->execute($args);
        $total = (int) $count->fetchColumn();

        $page = max(1, min($page, (int) ceil(max($total, 1) / $perPage)));
        $stmt = $pdo->prepare(
            "SELECT * FROM audit_logs WHERE {$whereSql} ORDER BY id DESC LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
        );
        $stmt->execute($args);

        $rows = array_map(static function (array $r): array {
            $r['meta'] = $r['meta'] !== null ? (json_decode((string) $r['meta'], true) ?: []) : [];

            return $r;
        }, $stmt->fetchAll());

        return new Paginator($rows, $page, $perPage, $total);
    }

    /** @return list<string> Ações já registradas — pro filtro da tela. */
    public static function distinctActions(): array
    {
        return Database::connection()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function prune(int $days): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL ? DAY');
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }
}
