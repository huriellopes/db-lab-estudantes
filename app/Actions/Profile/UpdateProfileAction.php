<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Core\Action;
use App\Core\Auth;
use App\Models\User;
use App\Support\ProfileFields;

/** Atualiza o nome de exibição. POST /profile. */
final class UpdateProfileAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $name = trim((string) ($_POST['name'] ?? ''));

        if (!ProfileFields::isValidName($name)) {
            $this->respond(false, 'Informe seu nome completo.', '/profile');
        }

        $userId = Auth::id();
        User::updateProfile($userId, $name);
        Auth::refresh(User::find($userId));

        $this->respond(true, 'Nome atualizado com sucesso.', '/profile');
    }
}
