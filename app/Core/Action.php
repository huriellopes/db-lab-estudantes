<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base pra "Single Action": uma classe, uma responsabilidade só, chamada via __invoke()
 * (ver App\Core\Router::dispatch() — uma rota aponta pra classe direto, sem método).
 * Os helpers (render/redirect/respond/genericError) são os mesmos de sempre, herdados de
 * Controller sem duplicar nada — a diferença real dessa classe pros Controllers "clássicos"
 * (Admin, Auth, Student...) é só a convenção de ter um método só em vez de vários
 * (index/store/update/destroy...). Novas rotas de responsabilidade única devem preferir
 * Action a Controller; controllers multi-ação continuam legítimos quando várias operações
 * relacionadas (CRUD de um mesmo recurso) genuinamente compartilham estado/contexto.
 */
abstract class Action extends Controller
{
    /** @param array<string, string> $params */
    abstract public function __invoke(array $params = []): void;
}
