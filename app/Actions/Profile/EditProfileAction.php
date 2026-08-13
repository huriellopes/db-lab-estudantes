<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Core\Action;
use App\Core\Auth;

/** GET /profile. */
final class EditProfileAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('profile/edit', [
            'pageTitle' => 'Meu perfil',
        ]);
    }
}
