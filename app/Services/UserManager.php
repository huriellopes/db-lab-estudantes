<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Entities\User;
use App\Models\RememberToken;
use App\Models\User as UserModel;
use App\Support\Role;
use Throwable;

/**
 * Operações de alto nível sobre uma conta de usuário que precisam coordenar a tabela
 * "users" da app com o estado real do MySQL (schemas e a conta MySQL da pessoa).
 */
final class UserManager
{
    /**
     * Cria um usuário completo: linha em "users" + conta MySQL real com o username
     * escolhido. É o único lugar que sabe montar um usuário "de verdade" — usado no
     * cadastro público, na criação pelo admin, e por UserFactory (seeders/testes).
     */
    public static function provisionNewUser(
        string $name,
        string $email,
        string $username,
        string $password,
        Role $role,
    ): User {
        $userId = null;

        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $userId = UserModel::create($name, $email, $passwordHash, $role);
            UserModel::assignInitialLogin($userId, $username);

            // Cria a conta MySQL real, com a MESMA senha da conta na plataforma — a
            // pessoa usa esse login/senha para acessar o phpMyAdmin depois. O prefixo de
            // schema nasce igual ao login, mas é fixo a partir daqui (ver assignInitialLogin).
            SchemaProvisioner::createMysqlAccount($username, $password, schemaPrefix: $username);

            return UserModel::find($userId);
        } catch (Throwable $e) {
            if ($userId !== null) {
                try {
                    UserModel::forceDelete($userId);
                } catch (Throwable $cleanupError) {
                    // ignora falha de limpeza, o erro principal já será relançado abaixo
                }
            }
            try {
                SchemaProvisioner::dropMysqlAccount($username);
            } catch (Throwable $cleanupError) {
                // ignora falha de limpeza, o erro principal já será relançado abaixo
            }
            throw $e;
        }
    }

    /**
     * Atualiza a senha tanto na app quanto na conta MySQL real da pessoa, e derruba todas as
     * sessões e cookies de "lembrar de mim" já emitidos — quem troca a senha porque
     * desconfia de vazamento espera que isso tire o invasor de dentro. Quem troca a própria
     * senha logado continua logado nesta sessão (ver UpdatePasswordAction).
     */
    public static function resetPassword(User $user, string $newPassword): void
    {
        UserModel::updatePasswordHash($user->id, password_hash($newPassword, PASSWORD_DEFAULT));
        SchemaProvisioner::changeMysqlPassword($user->mysqlLogin, $newPassword);
        self::revokeAllSessions($user->id);
    }

    /** Sessões abertas (session_version) e cookies de "lembrar de mim" de todos os dispositivos. */
    private static function revokeAllSessions(int $userId): void
    {
        UserModel::bumpSessionVersion($userId);
        RememberToken::revokeAllFor($userId);
    }

    /**
     * Renomeia o login MySQL da pessoa (usado também no phpMyAdmin e para logar na app).
     * Não renomeia os databases já criados — MySQL não tem um "RENAME DATABASE" seguro,
     * e os GRANTs continuam válidos porque RENAME USER os preserva. O prefixo de schema
     * também não muda: schemas novos continuam saindo como "<schema_prefix>__label".
     */
    public static function renameMysqlLogin(User $user, string $newMysqlLogin): void
    {
        SchemaProvisioner::renameMysqlAccount($user->mysqlLogin, $newMysqlLogin);
        UserModel::setMysqlLogin($user->id, $newMysqlLogin);
    }

    /** Ativa ou desativa a conta: bloqueia/libera o login na app E o acesso MySQL/phpMyAdmin. */
    public static function setActive(User $user, bool $active): void
    {
        UserModel::setActive($user->id, $active);

        if ($active) {
            SchemaProvisioner::unlockMysqlAccount($user->mysqlLogin);
        } else {
            SchemaProvisioner::lockMysqlAccount($user->mysqlLogin);
            self::revokeAllSessions($user->id);
        }
    }

    /**
     * Soft delete, como o SoftDeletes do Laravel: marca deleted_at e bloqueia o acesso
     * MySQL, mas NÃO apaga os databases nem a conta MySQL — dá pra restaurar depois.
     */
    public static function softDelete(User $user): void
    {
        SchemaProvisioner::lockMysqlAccount($user->mysqlLogin);
        UserModel::softDelete($user->id);
        self::revokeAllSessions($user->id);
    }

    /** Desfaz o softDelete: libera o acesso MySQL de novo e limpa deleted_at. */
    public static function restore(User $user): void
    {
        SchemaProvisioner::unlockMysqlAccount($user->mysqlLogin);
        UserModel::restore($user->id);
    }
}
