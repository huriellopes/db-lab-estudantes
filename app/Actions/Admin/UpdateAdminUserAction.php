<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\User as UserModel;
use App\Support\ProfileFields;

/** POST /admin/usuarios/{id}. */
final class UpdateAdminUserAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!ProfileFields::isValidName($name) || !ProfileFields::isValidEmail($email)) {
            $this->respond(false, 'Informe um nome e e-mail válidos.', '/admin/usuarios');
        }

        $existing = UserModel::findByEmail($email);
        if ($existing !== null && $existing->id !== $target->id) {
            $this->respond(false, 'Já existe uma conta com este e-mail.', '/admin/usuarios');
        }

        UserModel::updateAccount($target->id, $name, $email);

        AuditLog::record('user.updated', 'user', $target->id, ['email' => $email]);
        $this->respond(true, 'Usuário atualizado.', '/admin/usuarios');
    }
}
