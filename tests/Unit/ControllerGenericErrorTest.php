<?php

declare(strict_types=1);

use App\Core\Controller;

it('returns a generic message following the "Não foi possível <ação>." pattern, never the raw exception', function () {
    $controller = new class () extends Controller {
        public function callGenericError(string $action, Throwable $e): string
        {
            return $this->genericError($action, $e);
        }
    };

    $message = $controller->callGenericError(
        'excluir a conta',
        new RuntimeException('SQLSTATE[23000]: detalhe interno sensível do banco'),
    );

    expect($message)->toBe('Não foi possível excluir a conta. Tente de novo em instantes.')
        ->and($message)->not->toContain('SQLSTATE')
        ->and($message)->not->toContain('detalhe interno sensível do banco');
});
