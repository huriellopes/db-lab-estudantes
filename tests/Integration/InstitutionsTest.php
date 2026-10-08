<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\DeletedModel;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Services\Archiver;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use App\Support\InviteCode;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('creates an institution with a fresh invite code and refuses a duplicate name', function () {
    $id = integrationInstitution();
    $institution = Institution::find($id);

    expect($institution->inviteCode)->toMatch(InviteCode::PATTERN)
        ->and(Institution::findByInviteCode($institution->inviteCode)?->id)->toBe($id);

    expect(fn () => InstitutionManager::create($institution->name))->toThrow(InstitutionException::class, 'Já existe');
});

it('lets a professor be in two institutions but a student in only one', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();

    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($b, $professor->mysqlLogin);
    InstitutionManager::addMember($a, $student->email);

    expect(InstitutionMember::institutionIdsOf($professor->id))->toEqualCanonicalizing([$a, $b])
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($a);

    expect(fn () => InstitutionManager::addMember($b, $student->email))->toThrow(InstitutionException::class, 'já está na instituição');

    // A regra vale no próprio MySQL, mesmo sem passar pelo InstitutionManager.
    expect(fn () => InstitutionMember::add($b, $student->id, 'aluno'))->toThrow(PDOException::class);
});

it('refuses admins and unknown people as members', function () {
    $id = integrationInstitution();
    $admin = integrationUser(Role::Admin);

    expect(fn () => InstitutionManager::addMember($id, $admin->email))->toThrow(InstitutionException::class, 'Admins')
        ->and(fn () => InstitutionManager::addMember($id, 'ninguem@example.test'))->toThrow(InstitutionException::class, 'Nenhuma conta');
});

it('archives an institution with its memberships and restores everything', function () {
    $id = integrationInstitution();
    $code = Institution::find($id)->inviteCode;
    $student = integrationUser();
    InstitutionManager::addMember($id, $student->email);

    InstitutionManager::delete($id);

    expect(Institution::find($id))->toBeNull()
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBeNull()
        ->and(Institution::nameTaken(DeletedModel::itemsOfBatch(batchOf('institution', $id))[0]['label']))->toBeTrue()
        ->and(Institution::inviteCodeTaken($code))->toBeTrue();

    Archiver::restore(batchOf('institution', $id));

    expect(Institution::find($id)?->inviteCode)->toBe($code)
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($id);
});

it('archives a single membership when it is removed', function () {
    $id = integrationInstitution();
    $student = integrationUser();
    InstitutionManager::addMember($id, $student->email);
    $memberId = InstitutionMember::forInstitution($id)[0]['id'];

    InstitutionManager::removeMember($memberId);

    $batch = batchOf('institution_member', $memberId);
    expect(InstitutionMember::institutionOfStudent($student->id))->toBeNull()
        ->and(DeletedModel::itemsOfBatch($batch)[0]['label'])->toBe("{$student->name} → " . Institution::find($id)->name . ' (aluno)');
});

it('keeps memberships consistent when the role changes', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($b, $professor->email);

    expect(fn () => InstitutionManager::syncRoleChange($professor, Role::Aluno))->toThrow(InstitutionException::class, 'vínculos');

    InstitutionManager::syncRoleChange($professor, Role::Admin);
    expect(InstitutionMember::institutionIdsOf($professor->id))->toBe([]);

    $student = integrationUser();
    InstitutionManager::addMember($a, $student->email);
    InstitutionManager::syncRoleChange($student, Role::Professor);
    expect(InstitutionMember::forUser($student->id)[0]['role'])->toBe('professor');
});

it('lists for a professor only the students of their institutions', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    [$mine, $other, $loose] = [integrationUser(), integrationUser(), integrationUser()];
    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($a, $mine->email);
    InstitutionManager::addMember($b, $other->email);

    $visible = App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Professor, $professor->id, null);

    expect($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($other->id)
        ->and($visible)->not->toContain($loose->id)
        ->and(App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Admin, 0, 'sem'))->toContain($loose->id)
        ->and(App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Admin, 0, 'sem'))->not->toContain($mine->id);
});

function batchOf(string $model, int $id): string
{
    $stmt = Database::connection()->prepare('SELECT batch_id FROM deleted_models WHERE model = ? AND model_id = ? AND is_root = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$model, $id]);

    return (string) $stmt->fetchColumn();
}
