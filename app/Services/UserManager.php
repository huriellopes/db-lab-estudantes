<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Entities\User;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;

/**
 * Operações de alto nível sobre uma conta de usuário que precisam coordenar a tabela
 * "users" da app com o estado real do MySQL (schemas e a conta MySQL da pessoa).
 */
final class UserManager
{
    /**
     * Apaga a conta por completo: todos os databases que a pessoa criou, a conta MySQL
     * dela, e por fim a linha em "users" (o que também remove os registros em
     * "schemas_criados" via ON DELETE CASCADE).
     */
    public static function deleteCompletely(User $user): void
    {
        foreach (SchemaRecord::allForUser($user->id) as $schema) {
            SchemaProvisioner::dropDatabase($schema->dbName);
        }

        SchemaProvisioner::dropMysqlAccount($user->mysqlLogin);
        UserModel::delete($user->id);
    }

    /** Atualiza a senha tanto na app quanto na conta MySQL real da pessoa. */
    public static function resetPassword(User $user, string $newPassword): void
    {
        UserModel::updatePasswordHash($user->id, password_hash($newPassword, PASSWORD_DEFAULT));
        SchemaProvisioner::changeMysqlPassword($user->mysqlLogin, $newPassword);
    }

    /**
     * Renomeia o login MySQL da pessoa (usado também no phpMyAdmin). Não renomeia os
     * databases já criados — MySQL não tem um "RENAME DATABASE" seguro, e os GRANTs
     * continuam válidos porque RENAME USER os preserva. Só o identificador de login muda.
     */
    public static function renameMysqlLogin(User $user, string $newMysqlLogin): void
    {
        SchemaProvisioner::renameMysqlAccount($user->mysqlLogin, $newMysqlLogin);
        UserModel::setMysqlLogin($user->id, $newMysqlLogin);
    }
}
