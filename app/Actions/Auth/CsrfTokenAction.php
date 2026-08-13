<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Core\Action;
use App\Support\Csrf;

/**
 * Token CSRF atual da sessão, em JSON — chamado pelo interceptor Axios (ver
 * resources/js/app.js) quando um POST volta 419 (token velho: aba aberta tempo demais, ou
 * sessão trocada em outra aba). GET não passa pela checagem central de public/index.php
 * (só POST verifica o token), então dá pra buscar um token válido sem precisar recarregar
 * a página — Csrf::token() cria um novo na sessão atual se for a primeira vez, ou devolve
 * o mesmo de sempre caso contrário. Funciona sem estar logado de propósito: o form de
 * login também depende de CSRF. GET /csrf-token.
 */
final class CsrfTokenAction extends Action
{
    public function __invoke(array $params = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(['token' => Csrf::token()]);
    }
}
