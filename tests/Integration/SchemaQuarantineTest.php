<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\SchemaQuarantine;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('moves tables with data into quarantine and back, saving views and triggers', function () {
    $owner = integrationUser();
    $db = integrationSchema($owner);
    $pdo = Database::connection();
    $pdo->exec("CREATE TABLE `{$db}`.aluno (id INT PRIMARY KEY, nome VARCHAR(50), nota INT DEFAULT 0)");
    $pdo->exec("INSERT INTO `{$db}`.aluno VALUES (1, 'Ana', 9), (2, 'Bia', 7)");
    $pdo->exec("CREATE VIEW `{$db}`.aprovados AS SELECT nome FROM `{$db}`.aluno WHERE nota >= 7");
    $pdo->exec("CREATE TRIGGER `{$db}`.nota_padrao BEFORE INSERT ON `{$db}`.aluno FOR EACH ROW BEGIN IF NEW.nota IS NULL THEN SET NEW.nota = 0; END IF; END");

    $schemaId = (int) $pdo->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();
    $moved = SchemaQuarantine::move($db, $schemaId);

    expect($moved['quarantine'])->toBe("_lixeira_s{$schemaId}")
        ->and(SchemaQuarantine::exists($db))->toBeFalse()
        ->and((int) $pdo->query("SELECT COUNT(*) FROM `_lixeira_s{$schemaId}`.aluno")->fetchColumn())->toBe(2)
        ->and($moved['objects'])->toHaveCount(2)
        ->and($moved['objects'][0])->toContain('VIEW')
        ->and($moved['objects'][1])->toContain('TRIGGER');

    SchemaQuarantine::restore($db, $moved['quarantine'], $owner->mysqlLogin);

    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeFalse()
        ->and($pdo->query("SELECT GROUP_CONCAT(nome ORDER BY id) FROM `{$db}`.aluno")->fetchColumn())->toBe('Ana,Bia');

    $grant = $pdo->prepare('SELECT 1 FROM mysql.db WHERE Db = ? AND User = ?');
    $grant->execute([$db, $owner->mysqlLogin]);
    expect($grant->fetch())->not->toBeFalse();
});

it('quarantines an empty schema and purges it', function () {
    $owner = integrationUser();
    $db = integrationSchema($owner, 'vazio');
    $schemaId = (int) Database::connection()->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();

    $moved = SchemaQuarantine::move($db, $schemaId);
    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeTrue()
        ->and(SchemaQuarantine::sizeBytes([$moved['quarantine']]))->toBe([$moved['quarantine'] => 0]);

    SchemaQuarantine::purge($moved['quarantine']);
    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeFalse();
});
