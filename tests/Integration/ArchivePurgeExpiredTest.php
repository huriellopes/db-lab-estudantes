<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\DeletedModel;
use App\Models\SavedQuery;
use App\Services\Archiver;
use App\Services\SchemaQuarantine;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

function ageBatch(string $batchId, int $days): void
{
    Database::connection()->prepare('UPDATE deleted_models SET deleted_at = NOW() - INTERVAL ? DAY WHERE batch_id = ?')->execute([$days, $batchId]);
}

it('purges only batches older than the retention, skipping the ones marked to keep', function () {
    $old = integrationUser();
    integrationSchema($old);
    $oldBatch = Archiver::archive('user', $old->id, 'user.deleted');
    $quarantine = DeletedModel::itemsOfBatch($oldBatch)[1]['meta']['quarantine'];
    ageBatch($oldBatch, 40);

    $kept = integrationUser();
    $keptBatch = Archiver::archive('user', $kept->id, 'user.deleted');
    ageBatch($keptBatch, 40);
    DeletedModel::setKeep($keptBatch, true);

    $recent = integrationUser();
    SavedQuery::create($recent->id, 'q-it recente', null, 'SELECT 1');
    $recentBatch = Archiver::archive('saved_query', SavedQuery::allForUser($recent->id)[0]->id, 'saved_query.deleted');
    ageBatch($recentBatch, 5);

    $simulated = Archiver::purgeExpired(30, apply: false);
    expect(array_column($simulated, 'batch_id'))->toBe([$oldBatch])
        ->and(DeletedModel::itemsOfBatch($oldBatch))->not->toBe([]);

    $purged = Archiver::purgeExpired(30, apply: true);
    $audit = Database::connection()->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.motivo')) FROM audit_logs WHERE action = 'archive.purged' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.batch')) = ?");
    $audit->execute([$oldBatch]);

    expect(array_column($purged, 'batch_id'))->toBe([$oldBatch])
        ->and(DeletedModel::itemsOfBatch($oldBatch))->toBe([])
        ->and(SchemaQuarantine::exists($quarantine))->toBeFalse()
        ->and(DeletedModel::itemsOfBatch($keptBatch))->not->toBe([])
        ->and(DeletedModel::itemsOfBatch($recentBatch))->not->toBe([])
        ->and($audit->fetchColumn())->toBe('expurgo automático (30 dias)');
});

it('does nothing when the retention is off', function () {
    $user = integrationUser();
    $batch = Archiver::archive('user', $user->id, 'user.deleted');
    ageBatch($batch, 400);

    expect(Archiver::purgeExpired(0, apply: true))->toBe([])
        ->and(DeletedModel::itemsOfBatch($batch))->not->toBe([]);
});

it('uses the retention saved in the platform over the environment value', function () {
    $pdo = Database::connection();
    $previous = App\Models\AppSetting::find(App\Models\AppSetting::ARCHIVE_RETENTION_DAYS);
    $_ENV['ARCHIVE_RETENTION_DAYS'] = '90';

    try {
        $pdo->exec("DELETE FROM app_settings WHERE name = 'archive_retention_days'");
        expect(App\Support\RetentionPolicy::current())->toBe(90);

        App\Models\AppSetting::set(App\Models\AppSetting::ARCHIVE_RETENTION_DAYS, '15', 'teste');
        expect(App\Support\RetentionPolicy::current())->toBe(15);

        App\Models\AppSetting::set(App\Models\AppSetting::ARCHIVE_RETENTION_DAYS, '0', 'teste');
        expect(App\Support\RetentionPolicy::current())->toBe(0);
    } finally {
        unset($_ENV['ARCHIVE_RETENTION_DAYS']);
        $pdo->exec("DELETE FROM app_settings WHERE name = 'archive_retention_days'");
        if ($previous !== null) {
            App\Models\AppSetting::set(App\Models\AppSetting::ARCHIVE_RETENTION_DAYS, $previous['value'], $previous['updated_by']);
        }
    }
});
