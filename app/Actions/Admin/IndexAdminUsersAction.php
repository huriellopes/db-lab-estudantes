<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\User;
use App\Models\User as UserModel;
use App\Support\Role;
use App\Support\TableFilter;
use App\Support\TableQuery;

/** GET /admin/usuarios. */
final class IndexAdminUsersAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $query = TableQuery::fromParams($_GET, ['name', 'email', 'role', 'status', 'created', 'last_login']);
        $paginator = TableFilter::paginate(
            UserModel::allManageable(),
            $query,
            searchText: static fn (User $u): string => "{$u->name} {$u->email} {$u->mysqlLogin} {$u->role->label()}",
            sortAccessors: [
                'name' => static fn (User $u): string => mb_strtolower($u->name),
                'email' => static fn (User $u): string => mb_strtolower($u->email),
                'role' => static fn (User $u): string => $u->role->label(),
                'status' => static fn (User $u): int => $u->active ? 1 : 0,
                'created' => static fn (User $u): int => $u->createdAt->getTimestamp(),
                // Quem nunca logou (lastLoginAt null) vai pro fim em qualquer direção.
                'last_login' => static fn (User $u): int => $u->lastLoginAt?->getTimestamp() ?? -1,
            ],
            perPage: 10,
        );

        $this->render('admin/users', [
            'pageTitle' => 'Usuários',
            'paginator' => $paginator,
            'query' => $query,
            'roles' => Role::cases(),
        ]);
    }
}
