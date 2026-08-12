<?php

namespace App\Services;

use App\Models\SchemaRecord;
use App\Models\User;

/**
 * Operações de alto nível sobre uma conta de usuário que precisam coordenar a tabela
 * "users" da app com o estado real do MySQL (schemas e a conta MySQL da pessoa).
 */
class UserManager
{
    /**
     * Apaga a conta por completo: todos os databases que a pessoa criou, a conta MySQL
     * dela, e por fim a linha em "users" (o que também remove os registros em
     * "schemas_criados" via ON DELETE CASCADE).
     */
    public static function deleteCompletely(array $user): void
    {
        foreach (SchemaRecord::allForUser((int) $user['id']) as $schema) {
            SchemaProvisioner::dropDatabase($schema['db_name']);
        }

        SchemaProvisioner::dropMysqlAccount($user['mysql_login']);
        User::delete((int) $user['id']);
    }

    /** Atualiza a senha tanto na app quanto na conta MySQL real da pessoa. */
    public static function resetPassword(array $user, string $newPassword): void
    {
        User::updatePasswordHash((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));
        SchemaProvisioner::changeMysqlPassword($user['mysql_login'], $newPassword);
    }
}
