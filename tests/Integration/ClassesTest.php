<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\ClassMember;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;
use App\Services\Archiver;
use App\Services\ClassException;
use App\Services\ClassManager;
use App\Services\InstitutionManager;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

function lastBatchOf(string $model, int $id): string
{
    $stmt = Database::connection()->prepare('SELECT batch_id FROM deleted_models WHERE model = ? AND model_id = ? AND is_root = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$model, $id]);

    return (string) $stmt->fetchColumn();
}

it('lets a professor of the institution create a class and makes them responsible', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    InstitutionManager::addMember($inst, $professor->email);

    $classId = integrationClass($inst, asActor($professor));

    expect(ClassMember::professorIdsOf($classId))->toBe([$professor->id])
        ->and(SchoolClass::find($classId)->institutionId)->toBe($inst);

    $outsider = integrationUser(Role::Professor);
    expect(fn () => integrationClass($inst, asActor($outsider)))->toThrow(ClassException::class, 'não é professor');
});

it('only accepts members of the same institution and only responsible professors edit', function () {
    [$inst, $other] = [integrationInstitution(), integrationInstitution()];
    [$professor, $colleague] = [integrationUser(Role::Professor), integrationUser(Role::Professor)];
    [$student, $foreigner] = [integrationUser(), integrationUser()];
    foreach ([$professor, $colleague, $student] as $u) {
        InstitutionManager::addMember($inst, $u->email);
    }
    InstitutionManager::addMember($other, $foreigner->email);
    $classId = integrationClass($inst, asActor($professor));

    ClassManager::addMember($classId, $student->email, asActor($professor));
    expect(ClassMember::isMember($classId, $student->id))->toBeTrue();

    expect(fn () => ClassManager::addMember($classId, $foreigner->email, asActor($professor)))->toThrow(ClassException::class, 'não está na instituição')
        ->and(fn () => ClassManager::rename($classId, 'Outro nome', asActor($colleague)))->toThrow(ClassException::class, 'responsáveis');

    ClassManager::addMember($classId, $colleague->email, asActor($professor));
    ClassManager::rename($classId, 'it-turma-renomeada', asActor($colleague));
    expect(SchoolClass::find($classId)->name)->toBe('it-turma-renomeada');
});

it('does not let a professor remove themselves (they leave instead)', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    InstitutionManager::addMember($inst, $professor->email);
    $classId = integrationClass($inst, asActor($professor));
    $memberId = ClassMember::forClass($classId)[0]['id'];

    // Sair da turma (com a regra do último responsável) está em CandidatesAndLeaveTest.
    expect(fn () => ClassManager::removeMember($memberId, asActor($professor)))->toThrow(ClassException::class, 'Sair da turma');
});

it('archives an institution with its classes and class memberships and restores all of it', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    $classId = integrationClass($inst, asActor($professor));
    ClassManager::addMember($classId, $student->email, asActor($professor));

    InstitutionManager::delete($inst);
    $batch = lastBatchOf('institution', $inst);
    $models = array_column(App\Models\DeletedModel::itemsOfBatch($batch), 'model');

    expect(SchoolClass::find($classId))->toBeNull()
        ->and($models)->toContain('class')
        ->and(array_count_values($models)['class_member'])->toBe(2);

    Archiver::restore($batch);
    expect(ClassMember::isMember($classId, $student->id))->toBeTrue()
        ->and(ClassMember::professorIdsOf($classId))->toBe([$professor->id]);
});

it('takes a student out of the classes when they leave the institution', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    $classId = integrationClass($inst, asActor($professor));
    ClassManager::addMember($classId, $student->email, asActor($professor));

    $membership = array_values(array_filter(InstitutionMember::forInstitution($inst), static fn ($m) => $m['user_id'] === $student->id))[0];
    InstitutionManager::removeMember($membership['id']);

    expect(ClassMember::isMember($classId, $student->id))->toBeFalse();
});

it('archives class memberships when the role changes and lists the student classes', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    $classId = integrationClass($inst, asActor($professor));
    ClassManager::addMember($classId, $student->email, asActor($professor));

    $mine = ClassMember::classesOfStudent($student->id);
    expect($mine)->toHaveCount(1)
        ->and($mine[0]['professors'])->toBe($professor->name);

    InstitutionManager::syncRoleChange($student, Role::Professor);
    expect(ClassMember::isMember($classId, $student->id))->toBeFalse();
});
