<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Support\AdminStats;
use App\Support\ErrorLogger;
use App\Support\Role;
use DateTimeImmutable;

/** Agregados pro painel do admin (GET /admin) — consultas só de leitura, uma por métrica. */
final class AdminMetrics
{
    public static function collect(): AdminStats
    {
        $pdo = Database::connection();
        $scalar = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

        return new AdminStats(
            alunos: User::countByRole(Role::Aluno),
            professores: User::countByRole(Role::Professor),
            admins: User::countByRole(Role::Admin),
            schemas: SchemaRecord::countAll(),
            inactiveUsers: $scalar('SELECT COUNT(*) FROM users WHERE active = 0'),
            deletedBatches: DeletedModel::countBatches(),
            activeLast7Days: $scalar('SELECT COUNT(*) FROM users WHERE last_login_at >= NOW() - INTERVAL 7 DAY'),
            activeLast30Days: $scalar('SELECT COUNT(*) FROM users WHERE last_login_at >= NOW() - INTERVAL 30 DAY'),
            neverLoggedIn: $scalar('SELECT COUNT(*) FROM users WHERE last_login_at IS NULL'),
            savedQueries: $scalar('SELECT COUNT(*) FROM saved_queries'),
            erDiagrams: $scalar('SELECT COUNT(*) FROM er_diagrams'),
            schemasTotalBytes: $scalar(
                'SELECT COALESCE(SUM(t.data_length + t.index_length), 0)
                 FROM information_schema.TABLES t
                 INNER JOIN schemas_criados s ON s.db_name = t.table_schema',
            ),
            signupsByDay: self::signupsByDay(14),
            largestSchemas: self::largestSchemas(5),
            errorsLast24h: ErrorLogger::countSince(new DateTimeImmutable('-24 hours')),
        );
    }

    /** @return list<array{date: string, total: int}> */
    private static function signupsByDay(int $days): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DATE(created_at) AS d, COUNT(*) AS total FROM users
             WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY DATE(created_at)',
        );
        $stmt->execute([$days - 1]);
        $byDate = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = (new DateTimeImmutable("-{$i} days"))->format('Y-m-d');
            $series[] = ['date' => $date, 'total' => (int) ($byDate[$date] ?? 0)];
        }

        return $series;
    }

    /** @return list<array{dbName: string, ownerName: string, bytes: int}> */
    private static function largestSchemas(int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.db_name, u.name AS owner_name, COALESCE(SUM(t.data_length + t.index_length), 0) AS bytes
             FROM schemas_criados s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN information_schema.TABLES t ON t.table_schema = s.db_name
             GROUP BY s.db_name, u.name
             ORDER BY bytes DESC
             LIMIT ?',
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'dbName' => (string) $r['db_name'],
            'ownerName' => (string) $r['owner_name'],
            'bytes' => (int) $r['bytes'],
        ], $stmt->fetchAll());
    }
}
