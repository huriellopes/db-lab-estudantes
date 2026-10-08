<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Entities\User as UserEntity;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\User;
use App\Support\InviteCode;
use App\Support\Role;

/**
 * Escrita de instituições e vínculos (só admin chama — ver app/Actions/Institution). Remover
 * vínculo e excluir instituição passam pelo Archiver: nada é apagado direto.
 */
final class InstitutionManager
{
    private const MAX_NAME = 120;

    public static function create(string $name): int
    {
        $name = self::validName($name, 0);
        $id = Institution::create($name, self::freshCode());
        AuditLog::record('institution.created', 'institution', $id, ['nome' => $name]);

        return $id;
    }

    public static function rename(int $id, string $name): void
    {
        $current = self::findOrFail($id);
        $name = self::validName($name, $id);
        Institution::rename($id, $name);
        AuditLog::record('institution.renamed', 'institution', $id, ['de' => $current->name, 'para' => $name]);
    }

    public static function regenerateCode(int $id): string
    {
        self::findOrFail($id);
        $code = self::freshCode();
        Institution::setInviteCode($id, $code);
        AuditLog::record('institution.code_regenerated', 'institution', $id);

        return $code;
    }

    public static function disableCode(int $id): void
    {
        self::findOrFail($id);
        Institution::setInviteCode($id, null);
        AuditLog::record('institution.code_disabled', 'institution', $id);
    }

    /** $identifier: e-mail ou username. O papel do vínculo vem da conta. */
    public static function addMember(int $institutionId, string $identifier): UserEntity
    {
        $institution = self::findOrFail($institutionId);
        $user = User::findByEmailOrUsername(trim($identifier))
            ?? throw new InstitutionException('Nenhuma conta com esse e-mail ou username.');

        if ($user->role === Role::Admin) {
            throw new InstitutionException('Admins já veem todas as instituições — não precisam de vínculo.');
        }
        if (in_array($institutionId, InstitutionMember::institutionIdsOf($user->id), true)) {
            throw new InstitutionException("{$user->name} já está nesta instituição.");
        }
        if ($user->role === Role::Aluno && ($current = InstitutionMember::institutionOfStudent($user->id)) !== null) {
            $other = Institution::find($current)?->name ?? "#{$current}";
            throw new InstitutionException("{$user->name} já está na instituição {$other}. Remova de lá antes (aluno fica em uma só).");
        }

        InstitutionMember::add($institutionId, $user->id, $user->role->value);
        AuditLog::record('institution.member_added', 'institution', $institutionId, ['usuario' => $user->email, 'papel' => $user->role->value, 'instituicao' => $institution->name]);

        return $user;
    }

    public static function removeMember(int $memberId): void
    {
        $member = InstitutionMember::find($memberId) ?? throw new InstitutionException('Vínculo não encontrado.');
        // Quem sai da instituição sai também das turmas dela (os vínculos de turma vão pro arquivo).
        ClassManager::archiveMembershipsOf($member['user_id'], $member['institution_id'], 'removido da instituição');
        Archiver::archive('institution_member', $memberId, 'institution.member_removed');
    }

    public static function delete(int $id): void
    {
        $institution = self::findOrFail($id);
        Archiver::archive('institution', $id, 'institution.deleted', ['nome' => $institution->name]);
    }

    /**
     * Chamado ANTES de trocar o papel da conta (UpdateAdminUserRoleAction): aluno só pode ter
     * um vínculo; admin não tem vínculo (os dele vão pro arquivo).
     */
    public static function syncRoleChange(UserEntity $user, Role $newRole): void
    {
        if ($newRole === $user->role) {
            return;
        }
        $memberships = InstitutionMember::forUser($user->id);
        if ($newRole === Role::Aluno && count($memberships) > 1) {
            throw new InstitutionException("{$user->name} está em " . count($memberships) . ' instituições; aluno fica em uma só. Remova os vínculos extras antes de trocar o papel.');
        }

        // O papel numa turma (responsável x aluno) deixa de fazer sentido com a troca.
        ClassManager::archiveMembershipsOf($user->id, null, 'troca de papel');

        if ($memberships === []) {
            return;
        }
        if ($newRole === Role::Admin) {
            foreach ($memberships as $m) {
                Archiver::archive('institution_member', $m['id'], 'institution.member_removed', ['motivo' => 'promovido a admin']);
            }

            return;
        }

        InstitutionMember::setRoleForUser($user->id, $newRole->value);
    }

    private static function findOrFail(int $id): \App\Models\Entities\Institution
    {
        return Institution::find($id) ?? throw new InstitutionException('Instituição não encontrada.');
    }

    private static function validName(string $name, int $exceptId): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > self::MAX_NAME) {
            throw new InstitutionException('Informe um nome entre 2 e ' . self::MAX_NAME . ' caracteres.');
        }
        if (Institution::nameTaken($name, $exceptId)) {
            throw new InstitutionException("Já existe uma instituição chamada {$name} (ou ela está em Dados excluídos).");
        }

        return $name;
    }

    private static function freshCode(): string
    {
        do {
            $code = InviteCode::generate();
        } while (Institution::inviteCodeTaken($code));

        return $code;
    }
}
