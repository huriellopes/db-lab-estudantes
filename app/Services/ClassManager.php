<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClassMember;
use App\Models\Entities\SchoolClass as SchoolClassEntity;
use App\Models\Entities\User as UserEntity;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\AuthenticatedUser;
use App\Support\ClassAccess;
use App\Support\Role;

/**
 * Escrita de turmas (professor responsável ou admin — ver ClassAccess). Remover vínculo e
 * excluir turma passam pelo Archiver: nada é apagado direto.
 */
final class ClassManager
{
    private const MAX_NAME = 120;

    public static function create(int $institutionId, string $name, AuthenticatedUser $actor): int
    {
        if ($actor->role !== Role::Admin && !in_array($institutionId, InstitutionMember::institutionIdsOf($actor->id), true)) {
            throw new ClassException('Você não é professor desta instituição.');
        }
        $name = self::validName($name, $institutionId, 0);

        $id = SchoolClass::create($institutionId, $name);
        if ($actor->role === Role::Professor) {
            ClassMember::add($id, $actor->id, 'professor');
        }
        AuditLog::record('class.created', 'class', $id, ['nome' => $name, 'instituicao' => $institutionId]);

        return $id;
    }

    public static function rename(int $classId, string $name, AuthenticatedUser $actor): void
    {
        $class = self::editableOrFail($classId, $actor);
        $name = self::validName($name, $class->institutionId, $classId);
        SchoolClass::rename($classId, $name);
        AuditLog::record('class.renamed', 'class', $classId, ['de' => $class->name, 'para' => $name]);
    }

    public static function addMember(int $classId, string $identifier, AuthenticatedUser $actor): UserEntity
    {
        $class = self::editableOrFail($classId, $actor);
        $user = User::findByEmailOrUsername(trim($identifier))
            ?? throw new ClassException('Nenhuma conta com esse e-mail ou username.');

        if ($user->role === Role::Admin) {
            throw new ClassException('Admins já veem todas as turmas — não precisam de vínculo.');
        }
        if (!in_array($class->institutionId, InstitutionMember::institutionIdsOf($user->id), true)) {
            throw new ClassException("{$user->name} não está na instituição {$class->institutionName} — um admin precisa vincular antes.");
        }
        if (ClassMember::isMember($classId, $user->id)) {
            throw new ClassException("{$user->name} já está nesta turma.");
        }

        ClassMember::add($classId, $user->id, $user->role->value);
        AuditLog::record('class.member_added', 'class', $classId, ['usuario' => $user->email, 'papel' => $user->role->value, 'turma' => $class->name]);

        return $user;
    }

    public static function removeMember(int $memberId, AuthenticatedUser $actor): void
    {
        $member = ClassMember::find($memberId) ?? throw new ClassException('Vínculo não encontrado.');
        self::editableOrFail($member['class_id'], $actor);

        if ($member['role'] === 'professor' && $actor->role !== Role::Admin && count(ClassMember::professorIdsOf($member['class_id'])) === 1) {
            throw new ClassException('A turma precisa de pelo menos um professor responsável. Adicione outro antes de remover este.');
        }

        Archiver::archive('class_member', $memberId, 'class.member_removed');
    }

    public static function delete(int $classId, AuthenticatedUser $actor): void
    {
        $class = self::editableOrFail($classId, $actor);
        Archiver::archive('class', $classId, 'class.deleted', ['nome' => $class->name, 'instituicao' => $class->institutionName]);
    }

    public static function canEdit(SchoolClassEntity $class, AuthenticatedUser $actor): bool
    {
        return ClassAccess::canEdit($actor->role, $actor->id, ClassMember::professorIdsOf($class->id));
    }

    /**
     * Vínculos de turma de alguém que saiu da instituição (ou de todas, na troca de papel) vão
     * para o arquivo — senão a pessoa continuaria numa turma de onde não faz mais parte.
     */
    public static function archiveMembershipsOf(int $userId, ?int $institutionId, string $reason): void
    {
        foreach (ClassMember::forUser($userId, $institutionId) as $membership) {
            Archiver::archive('class_member', $membership['id'], 'class.member_removed', ['motivo' => $reason]);
        }
    }

    private static function editableOrFail(int $classId, AuthenticatedUser $actor): SchoolClassEntity
    {
        $class = SchoolClass::find($classId) ?? throw new ClassException('Turma não encontrada.');
        if (!self::canEdit($class, $actor)) {
            throw new ClassException('Só os professores responsáveis pela turma (ou um admin) podem alterá-la.');
        }

        return $class;
    }

    private static function validName(string $name, int $institutionId, int $exceptId): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > self::MAX_NAME) {
            throw new ClassException('Informe um nome entre 2 e ' . self::MAX_NAME . ' caracteres.');
        }
        if (SchoolClass::nameTaken($institutionId, $name, $exceptId)) {
            throw new ClassException("Já existe uma turma chamada {$name} nesta instituição.");
        }

        return $name;
    }
}
