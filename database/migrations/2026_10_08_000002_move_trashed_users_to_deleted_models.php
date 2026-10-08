<?php

declare(strict_types=1);

use App\Core\Migration;
use App\Models\DeletedModel;
use App\Services\Archiver;
use App\Services\SchemaProvisioner;

/**
 * Leva quem estava na lixeira antiga (users.deleted_at) pro arquivo de excluídos, do mesmo
 * jeito que uma exclusão nova faria (lote com schemas em quarentena), e remove a coluna.
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $ids = $pdo->query('SELECT id FROM users WHERE deleted_at IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            Archiver::archive('user', (int) $id, 'user.deleted', ['origem' => 'migração da lixeira antiga'], actor: ['id' => null, 'name' => 'migração']);
        }

        $pdo->exec('ALTER TABLE users DROP COLUMN deleted_at');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');

        $batches = $pdo->query("SELECT batch_id, model_id FROM deleted_models WHERE is_root = 1 AND model = 'user'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($batches as $batchId => $userId) {
            $login = (string) DeletedModel::itemsOfBatch($batchId)[0]['values']['mysql_login'];
            Archiver::restore($batchId);
            $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?')->execute([$userId]);
            SchemaProvisioner::lockMysqlAccount($login);
        }
    }
};
