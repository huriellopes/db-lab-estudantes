<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** POST /admin/usuarios/{id}/excluir. */
final class DestroyAdminUserAction extends AdminUserAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $target = $this->findManageableUserOrFail((int) $params['id']);

        try {
            Archiver::archive('user', $target->id, 'user.deleted', ['email' => $target->email]);
            $this->respond(true, "Conta de {$target->name} movida para Dados excluídos.", '/admin/usuarios');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/admin/usuarios');
        }
    }
}
