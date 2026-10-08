<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\Institution as InstitutionEntity;

/** Tabela institutions. Escrita passa por App\Services\InstitutionManager. */
final class Institution
{
    private const WITH_COUNTS = "SELECT i.*,
            (SELECT COUNT(*) FROM institution_members m WHERE m.institution_id = i.id AND m.role = 'professor') AS professors,
            (SELECT COUNT(*) FROM institution_members m WHERE m.institution_id = i.id AND m.role = 'aluno') AS students
        FROM institutions i";

    /** @return list<InstitutionEntity> */
    public static function all(): array
    {
        return array_map(InstitutionEntity::fromRow(...), Database::connection()->query(self::WITH_COUNTS . ' ORDER BY i.name')->fetchAll());
    }

    public static function find(int $id): ?InstitutionEntity
    {
        $stmt = Database::connection()->prepare(self::WITH_COUNTS . ' WHERE i.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : InstitutionEntity::fromRow($row);
    }

    public static function findByInviteCode(string $code): ?InstitutionEntity
    {
        $stmt = Database::connection()->prepare(self::WITH_COUNTS . ' WHERE i.invite_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : InstitutionEntity::fromRow($row);
    }

    /** Nome em uso por outra instituição — ou reservado por uma que está em Dados excluídos. */
    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM institutions WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId]);

        return $stmt->fetch() !== false || DeletedModel::isReserved('institution_name', $name);
    }

    public static function inviteCodeTaken(string $code): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM institutions WHERE invite_code = ?');
        $stmt->execute([$code]);

        return $stmt->fetch() !== false || DeletedModel::isReserved('invite_code', $code);
    }

    public static function create(string $name, string $inviteCode): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO institutions (name, invite_code) VALUES (?, ?)')->execute([$name, $inviteCode]);

        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE institutions SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public static function setInviteCode(int $id, ?string $code): void
    {
        Database::connection()->prepare('UPDATE institutions SET invite_code = ? WHERE id = ?')->execute([$code, $id]);
    }
}
