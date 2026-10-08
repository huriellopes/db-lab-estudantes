<?php

declare(strict_types=1);

use App\Models\ClassMember;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\SchoolClass;
use App\Services\Archiver;
use App\Services\ClassException;
use App\Services\ClassManager;
use App\Services\InstitutionManager;
use App\Support\InviteCode;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

/** @return array{0: int, 1: int, 2: App\Models\Entities\User} instituição, turma e o professor responsável */
function classWithResponsible(): array
{
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    InstitutionManager::addMember($inst, $professor->email);

    return [$inst, integrationClass($inst, asActor($professor)), $professor];
}

it('gives every new class its own code, distinct from the institution code', function () {
    [$inst, $classId] = classWithResponsible();
    $code = SchoolClass::find($classId)->inviteCode;

    expect($code)->toMatch(InviteCode::PATTERN)
        ->and($code)->not->toBe(Institution::find($inst)->inviteCode)
        ->and(SchoolClass::findByInviteCode($code)?->id)->toBe($classId);
});

it('lets a student without institution join the class and its institution by the class code', function () {
    [$inst, $classId] = classWithResponsible();
    $student = integrationUser();

    $joined = ClassManager::joinByCode(strtolower(str_replace('-', '', SchoolClass::find($classId)->inviteCode)), asActor($student));

    expect($joined['type'])->toBe('class')
        ->and(ClassMember::isMember($classId, $student->id))->toBeTrue()
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($inst);
});

it('refuses a class code from another institution and repeated or invalid codes', function () {
    [, $classId] = classWithResponsible();
    $other = integrationInstitution();
    $student = integrationUser();
    InstitutionManager::addMember($other, $student->email);
    $code = SchoolClass::find($classId)->inviteCode;

    expect(fn () => ClassManager::joinByCode($code, asActor($student)))->toThrow(ClassException::class, 'você está em')
        ->and(fn () => ClassManager::joinByCode('ZZZZ-ZZZZ', asActor($student)))->toThrow(ClassException::class, 'Código inválido')
        ->and(fn () => ClassManager::joinByCode('abc', asActor($student)))->toThrow(ClassException::class, 'Código inválido');

    $classmate = integrationUser();
    ClassManager::joinByCode($code, asActor($classmate));
    expect(fn () => ClassManager::joinByCode($code, asActor($classmate)))->toThrow(ClassException::class, 'já está nesta turma');
});

it('accepts the institution code too, for students who registered without one', function () {
    $inst = integrationInstitution();
    $student = integrationUser();

    $joined = ClassManager::joinByCode(Institution::find($inst)->inviteCode, asActor($student));

    expect($joined['type'])->toBe('institution')
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($inst)
        ->and(fn () => ClassManager::joinByCode(Institution::find($inst)->inviteCode, asActor($student)))->toThrow(ClassException::class, 'já está nesta instituição');
});

it('only lets students join by code', function () {
    [, $classId] = classWithResponsible();
    $professor = integrationUser(Role::Professor);

    expect(fn () => ClassManager::joinByCode(SchoolClass::find($classId)->inviteCode, asActor($professor)))->toThrow(ClassException::class, 'Só alunos');
});

it('lets responsible professors regenerate or disable the code, and reserves it while archived', function () {
    [, $classId, $professor] = classWithResponsible();
    $old = SchoolClass::find($classId)->inviteCode;

    $new = ClassManager::regenerateCode($classId, asActor($professor));
    expect($new)->not->toBe($old)
        ->and(SchoolClass::findByInviteCode($old))->toBeNull();

    ClassManager::disableCode($classId, asActor($professor));
    expect(SchoolClass::find($classId)->inviteCode)->toBeNull();

    $code = ClassManager::regenerateCode($classId, asActor($professor));
    ClassManager::delete($classId, asActor($professor));
    expect(SchoolClass::inviteCodeTaken($code))->toBeTrue();

    $batch = App\Core\Database::connection()->query("SELECT batch_id FROM deleted_models WHERE model = 'class' AND model_id = {$classId}")->fetchColumn();
    Archiver::restore((string) $batch);
    expect(SchoolClass::find($classId)->inviteCode)->toBe($code);
});
