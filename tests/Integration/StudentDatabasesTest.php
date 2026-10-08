<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\InstitutionManager;
use App\Services\StudentDatabases;
use App\Services\StudentDataEditor;
use App\Services\StudentDataException;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

/** @return array{0: App\Support\AuthenticatedUser, 1: string, 2: PDO} professor, banco do aluno, conexão do professor */
function studentDbFixture(): array
{
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    $db = integrationSchema($student);
    $app = Database::connection();
    $app->exec("CREATE TABLE `{$db}`.cliente (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(40) NOT NULL, cidade VARCHAR(40) NULL)");
    $app->exec("CREATE TABLE `{$db}`.pedido (id INT PRIMARY KEY, cliente_id INT, FOREIGN KEY (cliente_id) REFERENCES `{$db}`.cliente(id))");
    $app->exec("CREATE TABLE `{$db}`.sem_pk (x INT)");
    $app->exec("INSERT INTO `{$db}`.cliente (nome, cidade) VALUES ('Ana', 'Recife'), ('Bia', NULL)");
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);

    return [asActor($professor), $db, Database::connectAs($professor->mysqlLogin, 'Integracao-Senha!9')];
}

it('shows tables, columns, primary keys and relations of a student database', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    expect(StudentDatabases::canAccess($prof, $db))->toBeTrue();
    $tables = array_column(StudentDatabases::structure($pdo, $db), null, 'name');

    expect(array_keys($tables))->toBe(['cliente', 'pedido', 'sem_pk'])
        ->and($tables['cliente']['primaryKey'])->toBe(['id'])
        ->and(array_column($tables['cliente']['columns'], 'name'))->toBe(['id', 'nome', 'cidade'])
        ->and($tables['cliente']['columns'][2]['nullable'])->toBeTrue()
        ->and($tables['pedido']['foreignKeys'])->toBe([['column' => 'cliente_id', 'refTable' => 'cliente', 'refColumn' => 'id']])
        ->and($tables['sem_pk']['primaryKey'])->toBe([]);

    $page = StudentDatabases::rows($pdo, $db, $tables['cliente'], 1);
    expect($page['total'])->toBe(2)
        ->and(array_column($page['rows'], 'nome'))->toBe(['Ana', 'Bia']);
});

it('updates and inserts rows as the professor, and audits without the values', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    StudentDataEditor::update($pdo, $prof, $db, 'cliente', ['id' => '2'], ['nome' => 'Beatriz', 'cidade' => 'qualquer'], ['cidade' => '1']);
    StudentDataEditor::insert($pdo, $prof, $db, 'cliente', ['nome' => 'Caio', 'cidade' => '', 'id' => ''], []);

    $rows = Database::connection()->query("SELECT id, nome, cidade FROM `{$db}`.cliente ORDER BY id")->fetchAll();
    expect($rows)->toBe([
        ['id' => 1, 'nome' => 'Ana', 'cidade' => 'Recife'],
        ['id' => 2, 'nome' => 'Beatriz', 'cidade' => null],
        ['id' => 3, 'nome' => 'Caio', 'cidade' => null],
    ]);

    $meta = (string) Database::connection()->query("SELECT meta FROM audit_logs WHERE action = 'professor.db_row_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
    expect(json_decode($meta, true)['tabela'])->toBe('cliente')
        ->and($meta)->not->toContain('Beatriz');
});

it('refuses editing without primary key, unknown columns and students of other institutions', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    expect(fn () => StudentDataEditor::update($pdo, $prof, $db, 'sem_pk', ['x' => '1'], ['x' => '2'], []))->toThrow(StudentDataException::class, 'chave primária')
        ->and(fn () => StudentDataEditor::update($pdo, $prof, $db, 'cliente', ['id' => '1'], ['senha' => 'x'], []))->toThrow(StudentDataException::class, 'Nenhuma coluna')
        ->and(fn () => StudentDataEditor::insert($pdo, $prof, $db, 'tabela_que_nao_existe', ['a' => '1'], []))->toThrow(StudentDataException::class, 'não encontrada');

    $outsider = integrationUser();
    $otherDb = integrationSchema($outsider);
    expect(StudentDatabases::canAccess($prof, $otherDb))->toBeFalse();
});

it('turns a MySQL refusal (broken relation) into a message for the professor', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    expect(fn () => StudentDataEditor::insert($pdo, $prof, $db, 'pedido', ['id' => '1', 'cliente_id' => '999'], []))
        ->toThrow(StudentDataException::class, 'O MySQL recusou');
});
