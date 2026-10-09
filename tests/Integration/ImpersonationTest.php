<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\AuditLog;
use App\Services\UserManager;
use App\Support\Role;

beforeEach(function () {
    requiresDatabase();
    $_SESSION = [];
});
afterEach(function () {
    $_SESSION = [];
    getenv('INTEGRATION') === '1' && cleanupIntegrationData();
});

function lastAudit(string $action): array
{
    $stmt = Database::connection()->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$action]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

it('switches to the target, tags audited actions with the admin and switches back', function () {
    $admin = integrationUser(Role::Admin);
    $aluno = integrationUser();
    Auth::login($admin, 'Integracao-Senha!9');
    $adminPasswordEnc = $_SESSION['mysql_password_enc'];

    Auth::impersonate($aluno);

    expect(Auth::id())->toBe($aluno->id)
        ->and(Auth::isImpersonating())->toBeTrue()
        ->and(Auth::impersonator()['id'])->toBe($admin->id)
        ->and(Auth::mysqlPassword())->toBeNull();

    AuditLog::record('it.impersonated_action', 'user', $aluno->id);
    $row = lastAudit('it.impersonated_action');
    expect((int) $row['user_id'])->toBe($aluno->id)
        ->and((int) $row['impersonator_id'])->toBe($admin->id)
        ->and($row['impersonator_name'])->toBe($admin->name);

    expect(Auth::stopImpersonating())->toBeTrue()
        ->and(Auth::id())->toBe($admin->id)
        ->and(Auth::isImpersonating())->toBeFalse()
        ->and($_SESSION['mysql_password_enc'])->toBe($adminPasswordEnc);

    AuditLog::record('it.after_impersonation');
    expect(lastAudit('it.after_impersonation')['impersonator_id'])->toBeNull();

    Database::connection()->exec("DELETE FROM audit_logs WHERE action LIKE 'it.%'");
});

it('goes back to the admin when the impersonated account is deactivated', function () {
    $admin = integrationUser(Role::Admin);
    $aluno = integrationUser();
    Auth::login($admin);
    Auth::impersonate($aluno);

    UserManager::setActive($aluno, false);
    Auth::enforceSession();

    expect(Auth::id())->toBe($admin->id)
        ->and(Auth::isImpersonating())->toBeFalse()
        ->and(lastAudit('impersonation.stop')['target_id'])->toBe((string) $aluno->id);
});

it('ends the whole session when the admin behind it is no longer an admin', function () {
    $admin = integrationUser(Role::Admin);
    $aluno = integrationUser();
    Auth::login($admin);
    Auth::impersonate($aluno);

    Database::connection()->prepare("UPDATE users SET role = 'professor' WHERE id = ?")->execute([$admin->id]);
    Auth::enforceSession();

    expect(Auth::check())->toBeFalse()
        ->and(Auth::isImpersonating())->toBeFalse();
});
