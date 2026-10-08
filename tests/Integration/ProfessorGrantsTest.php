<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\InstitutionMember;
use App\Services\InstitutionManager;
use App\Services\ProfessorGrants;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('lets the professor read, insert and update the student data, but never delete or drop', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    $db = integrationSchema($student);
    Database::connection()->exec("CREATE TABLE `{$db}`.nota (id INT PRIMARY KEY, valor INT)");
    Database::connection()->exec("INSERT INTO `{$db}`.nota VALUES (1, 5)");
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);

    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toBe([App\Support\SchemaNameBuilder::grantPattern($student->schemaPrefix)]);

    $pdo = Database::connectAs($professor->mysqlLogin, 'Integracao-Senha!9');
    $pdo->exec("UPDATE `{$db}`.nota SET valor = 9 WHERE id = 1");
    $pdo->exec("INSERT INTO `{$db}`.nota VALUES (2, 7)");
    $rows = $pdo->query("SELECT valor FROM `{$db}`.nota ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expect(array_map('intval', $rows))->toBe([9, 7]);

    foreach (["DELETE FROM `{$db}`.nota", "DROP TABLE `{$db}`.nota", "TRUNCATE TABLE `{$db}`.nota", "ALTER TABLE `{$db}`.nota ADD x INT", "DROP DATABASE `{$db}`"] as $forbidden) {
        expect(fn () => $pdo->exec($forbidden))->toThrow(PDOException::class);
    }
    expect((int) Database::connection()->query("SELECT COUNT(*) FROM `{$db}`.nota")->fetchColumn())->toBe(2);
});

it('revokes the access when the student leaves the institution', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    $membership = array_values(array_filter(InstitutionMember::forInstitution($inst), static fn ($m) => $m['user_id'] === $student->id))[0];

    InstitutionManager::removeMember($membership['id']);

    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toBe([]);
});

it('gives nothing for students of another institution and is idempotent', function () {
    [$mine, $other] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    $foreigner = integrationUser();
    InstitutionManager::addMember($mine, $professor->email);
    InstitutionManager::addMember($other, $foreigner->email);

    expect(ProfessorGrants::desiredFor($professor->id))->toBe([])
        ->and(ProfessorGrants::syncUser(App\Models\User::find($professor->id)))->toBe(['grant' => 0, 'revoke' => 0]);
});

it('revokes the professor grants when the account stops being a professor', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toHaveCount(1);

    App\Models\User::updateRole($professor->id, Role::Aluno);
    ProfessorGrants::syncAll();

    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toBe([]);
});
