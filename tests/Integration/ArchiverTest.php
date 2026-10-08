<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\DeletedModel;
use App\Models\SavedQuery;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\ArchiveException;
use App\Services\Archiver;
use App\Services\SchemaQuarantine;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

function accountLocked(string $login): bool
{
    $stmt = Database::connection()->prepare("SELECT account_locked FROM mysql.user WHERE User = ? AND Host = '%'");
    $stmt->execute([$login]);

    return $stmt->fetchColumn() === 'Y';
}

function auditCount(string $action, string $batchId): int
{
    $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.batch')) = ?");
    $stmt->execute([$action, $batchId]);

    return (int) $stmt->fetchColumn();
}

it('archives a user with schema, query and diagram, restores everything and audits both', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    Database::connection()->exec("CREATE TABLE `{$db}`.t (id INT PRIMARY KEY, v VARCHAR(10))");
    Database::connection()->exec("INSERT INTO `{$db}`.t VALUES (1, 'um')");
    Database::connection()->exec("CREATE TRIGGER `{$db}`.trg BEFORE INSERT ON `{$db}`.t FOR EACH ROW SET NEW.v = UPPER(NEW.v)");
    SavedQuery::create($user->id, 'q-it consulta', $db, 'SELECT 1');

    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    $items = DeletedModel::itemsOfBatch($batch);
    expect(array_column($items, 'model'))->toBe(['user', 'schema', 'saved_query'])
        ->and($items[0]['is_root'])->toBeTrue()
        ->and(User::find($user->id))->toBeNull()
        ->and(SchemaRecord::nameTaken($db))->toBeFalse()
        ->and(SchemaQuarantine::exists($db))->toBeFalse()
        ->and(accountLocked($user->mysqlLogin))->toBeTrue()
        ->and(auditCount('user.deleted', $batch))->toBe(1);

    Archiver::restore($batch);

    $restored = User::find($user->id);
    expect($restored?->email)->toBe($user->email)
        ->and($restored?->passwordHash)->toBe($user->passwordHash)
        ->and(SchemaRecord::nameTaken($db))->toBeTrue()
        ->and(Database::connection()->query("SELECT v FROM `{$db}`.t WHERE id = 1")->fetchColumn())->toBe('um')
        ->and(accountLocked($user->mysqlLogin))->toBeFalse()
        ->and(DeletedModel::itemsOfBatch($batch))->toBe([])
        ->and(auditCount('archive.restored', $batch))->toBe(1);

    $titles = array_map(static fn ($q) => $q->title, SavedQuery::allForUser($user->id));
    expect($titles)->toContain('q-it consulta')
        ->and($titles)->toContain("Restaurar objetos de {$db}");
});

it('refuses to restore when the e-mail was taken meanwhile, without changing anything', function () {
    $user = integrationUser();
    $batch = Archiver::archive('user', $user->id, 'user.deleted');
    Database::connection()->prepare('INSERT INTO users (name, email, password_hash, role, mysql_login, schema_prefix) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['Integração intrusa', $user->email, 'x', 'aluno', 'itzzzzzzzz', 'itzzzzzzzz']);

    expect(Archiver::conflicts($batch))->toContain("O e-mail {$user->email} já pertence a outra conta.");
    expect(fn () => Archiver::restore($batch))->toThrow(ArchiveException::class);
    expect(DeletedModel::itemsOfBatch($batch))->toHaveCount(1)
        ->and(auditCount('archive.restore_failed', $batch))->toBe(1);
});

it('purges a batch: quarantine and MySQL account are gone, the audit stays', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    $batch = Archiver::archive('user', $user->id, 'user.deleted');
    $quarantine = DeletedModel::itemsOfBatch($batch)[1]['meta']['quarantine'];

    Archiver::purge($batch);

    $accounts = Database::connection()->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
    $accounts->execute([$user->mysqlLogin]);
    expect(SchemaQuarantine::exists($quarantine))->toBeFalse()
        ->and((int) $accounts->fetchColumn())->toBe(0)
        ->and(DeletedModel::itemsOfBatch($batch))->toBe([])
        ->and(auditCount('archive.purged', $batch))->toBe(1)
        ->and(auditCount('user.deleted', $batch))->toBe(1);
});

it('archives a schema removed outside the platform without data and refuses to restore it', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    Database::connection()->exec("DROP DATABASE `{$db}`");
    $schemaId = (int) Database::connection()->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();

    $batch = Archiver::archive('schema', $schemaId, 'schema.removed_outside', origin: Archiver::ORIGIN_OUTSIDE);

    expect(DeletedModel::itemsOfBatch($batch)[0]['meta'])->toBe(['removed_outside' => true]);
    expect(fn () => Archiver::restore($batch))->toThrow(ArchiveException::class, 'removido fora da plataforma');
});

it('archives a single saved query and brings it back', function () {
    $user = integrationUser();
    SavedQuery::create($user->id, 'q-it sozinha', null, 'SELECT 2');
    $id = SavedQuery::allForUser($user->id)[0]->id;

    $batch = Archiver::archive('saved_query', $id, 'saved_query.deleted');
    expect(SavedQuery::findOwned($id, $user->id))->toBeNull();

    Archiver::restore($batch);
    expect(SavedQuery::findOwned($id, $user->id)?->title)->toBe('q-it sozinha');
});
