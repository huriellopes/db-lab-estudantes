<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Models\User;
use App\Support\ErrorLogger;
use App\Support\ProfessorGrantDiff;
use App\Support\Role;
use App\Support\SchemaNameBuilder;
use PDO;
use Throwable;

/**
 * Permissões da conta MySQL do professor sobre os bancos dos alunos das instituições dele:
 * exatamente SELECT, INSERT, UPDATE no prefixo de cada aluno — nunca DELETE, DROP ou ALTER. É o
 * que garante, no próprio MySQL, que o professor ajuda (vê e edita dados) mas não exclui nada,
 * na tela da plataforma, no phpMyAdmin ou num SGBD.
 *
 * Recalculado do zero a cada mudança de vínculo (idempotente): o desejado sai das tabelas de
 * instituição, o atual de mysql.db (linhas com Select/Insert/Update = Y e Delete/Drop/Alter = N —
 * o GRANT do próprio prefixo de cada conta é ALL, então nunca entra nessa conta).
 */
final class ProfessorGrants
{
    /** @return list<string> */
    public static function desiredFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT u.schema_prefix FROM institution_members p
             INNER JOIN institution_members s ON s.institution_id = p.institution_id AND s.role = 'aluno'
             INNER JOIN users u ON u.id = s.user_id AND u.role = 'aluno'
             WHERE p.user_id = ? AND p.role = 'professor' ORDER BY u.schema_prefix",
        );
        $stmt->execute([$userId]);

        return array_map(SchemaNameBuilder::grantPattern(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public static function currentFor(string $login): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT Db FROM mysql.db WHERE User = ? AND Host = '%'
               AND Select_priv = 'Y' AND Insert_priv = 'Y' AND Update_priv = 'Y'
               AND Delete_priv = 'N' AND Drop_priv = 'N' AND Alter_priv = 'N'
             ORDER BY Db",
        );
        $stmt->execute([$login]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{grant: int, revoke: int} */
    public static function syncUser(UserEntity $user): array
    {
        $desired = $user->role === Role::Professor ? self::desiredFor($user->id) : [];
        $diff = ProfessorGrantDiff::between($desired, self::currentFor($user->mysqlLogin));
        $pdo = Database::connection();
        $account = $pdo->quote($user->mysqlLogin) . "@'%'";

        foreach ($diff['grant'] as $pattern) {
            $pdo->exec('GRANT SELECT, INSERT, UPDATE ON `' . str_replace('`', '``', $pattern) . "`.* TO {$account}");
        }
        foreach ($diff['revoke'] as $pattern) {
            $pdo->exec('REVOKE SELECT, INSERT, UPDATE ON `' . str_replace('`', '``', $pattern) . "`.* FROM {$account}");
        }

        return ['grant' => count($diff['grant']), 'revoke' => count($diff['revoke'])];
    }

    /** @return array{users: int, grant: int, revoke: int} */
    public static function syncAll(): array
    {
        $total = ['users' => 0, 'grant' => 0, 'revoke' => 0];
        foreach (User::all() as $user) {
            if ($user->role === Role::Admin) {
                continue;
            }
            try {
                $done = self::syncUser($user);
            } catch (Throwable $e) {
                ErrorLogger::exception($e, 'error'); // uma conta com problema não impede as outras
                continue;
            }
            $total['users']++;
            $total['grant'] += $done['grant'];
            $total['revoke'] += $done['revoke'];
        }

        return $total;
    }

    /** Depois de mudar vínculos: sincroniza sem nunca derrubar a ação principal. */
    public static function syncAllQuietly(): void
    {
        try {
            self::syncAll();
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'error');
        }
    }
}
