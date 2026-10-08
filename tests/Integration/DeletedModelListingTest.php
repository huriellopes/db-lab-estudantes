<?php

declare(strict_types=1);

use App\Models\DeletedModel;
use App\Services\Archiver;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('lists one row per batch with related counts, filtered by type and search', function () {
    $user = integrationUser();
    integrationSchema($user);
    App\Models\SavedQuery::create($user->id, 'q-it listada', null, 'SELECT 1');
    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    $page = DeletedModel::paginateBatches('user', $user->email, 0, 1);
    expect($page->total)->toBe(1)
        ->and($page->items[0]['batch_id'])->toBe($batch)
        ->and($page->items[0]['related'])->toBe(['schema' => 1, 'saved_query' => 1])
        ->and($page->items[0]['items'])->toHaveCount(3)
        ->and($page->items[0]['removed_outside'])->toBeFalse();

    expect(DeletedModel::paginateBatches('schema', $user->email, 0, 1)->total)->toBe(0)
        ->and(DeletedModel::paginateBatches(null, 'q-it listada', 0, 1)->total)->toBe(1);
});
