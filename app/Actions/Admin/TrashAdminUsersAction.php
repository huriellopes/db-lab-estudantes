<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Entities\User;
use App\Models\User as UserModel;
use App\Support\TableFilter;
use App\Support\TableQuery;

/** GET /admin/usuarios/lixeira. */
final class TrashAdminUsersAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $query = TableQuery::fromParams($_GET, ['name', 'email', 'role', 'deleted']);
        $paginator = TableFilter::paginate(
            UserModel::trashed(),
            $query,
            searchText: static fn (User $u): string => "{$u->name} {$u->email} {$u->mysqlLogin} {$u->role->label()}",
            sortAccessors: [
                'name' => static fn (User $u): string => mb_strtolower($u->name),
                'email' => static fn (User $u): string => mb_strtolower($u->email),
                'role' => static fn (User $u): string => $u->role->label(),
                'deleted' => static fn (User $u): int => $u->deletedAt?->getTimestamp() ?? 0,
            ],
            perPage: 10,
        );

        $this->render('admin/trash', [
            'pageTitle' => 'Lixeira',
            'paginator' => $paginator,
            'query' => $query,
        ]);
    }
}
