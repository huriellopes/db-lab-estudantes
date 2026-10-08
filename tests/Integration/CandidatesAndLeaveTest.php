<?php

declare(strict_types=1);

use App\Models\ClassMember;
use App\Models\InstitutionMember;
use App\Services\ClassException;
use App\Services\ClassManager;
use App\Services\InstitutionManager;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

/** @param list<array{id: int}> $results */
function candidateIds(array $results): array
{
    return array_column($results, 'id');
}

it('suggests for an institution only people who can still be linked to it', function () {
    [$inst, $other] = [integrationInstitution(), integrationInstitution()];
    [$free, $member, $elsewhere] = [integrationUser(), integrationUser(), integrationUser()];
    $professorElsewhere = integrationUser(Role::Professor);
    $admin = integrationUser(Role::Admin);
    InstitutionManager::addMember($inst, $member->email);
    InstitutionManager::addMember($other, $elsewhere->email);
    InstitutionManager::addMember($other, $professorElsewhere->email);

    $ids = candidateIds(InstitutionMember::candidates($inst, 'Integração'));

    expect($ids)->toContain($free->id)
        ->and($ids)->toContain($professorElsewhere->id) // professor pode estar em várias
        ->and($ids)->not->toContain($member->id)         // já está
        ->and($ids)->not->toContain($elsewhere->id)      // aluno já está em outra
        ->and($ids)->not->toContain($admin->id);          // admin nunca é membro

    expect(InstitutionMember::candidates($inst, $free->mysqlLogin)[0]['email'])->toBe($free->email)
        ->and(InstitutionMember::candidates($inst, 'I'))->toBe([])          // menos de 2 caracteres
        ->and(InstitutionMember::candidates($inst, '%_%'))->toBe([]);        // curinga do LIKE é texto
});

it('suggests for a class only people of its institution who are not in it yet', function () {
    [$inst, $other] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    [$inClass, $notYet, $foreigner] = [integrationUser(), integrationUser(), integrationUser()];
    foreach ([$professor, $inClass, $notYet] as $u) {
        InstitutionManager::addMember($inst, $u->email);
    }
    InstitutionManager::addMember($other, $foreigner->email);
    $classId = integrationClass($inst, asActor($professor));
    ClassManager::addMember($classId, $inClass->email, asActor($professor));

    $ids = candidateIds(ClassMember::candidates($classId, 'Integração'));

    expect($ids)->toBe([$notYet->id]);
});

it('lets a professor leave a class, but not remove themselves nor leave it without a responsible', function () {
    $inst = integrationInstitution();
    [$professor, $colleague] = [integrationUser(Role::Professor), integrationUser(Role::Professor)];
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $colleague->email);
    $classId = integrationClass($inst, asActor($professor));
    $ownMembership = ClassMember::forClass($classId)[0]['id'];

    expect(fn () => ClassManager::leave($classId, asActor($professor)))->toThrow(ClassException::class, 'pelo menos um')
        ->and(fn () => ClassManager::removeMember($ownMembership, asActor($professor)))->toThrow(ClassException::class, 'Sair da turma');

    ClassManager::addMember($classId, $colleague->email, asActor($professor));
    ClassManager::leave($classId, asActor($professor));

    expect(ClassMember::professorIdsOf($classId))->toBe([$colleague->id])
        ->and(fn () => ClassManager::leave($classId, asActor($professor)))->toThrow(ClassException::class, 'não está nesta turma');
});
