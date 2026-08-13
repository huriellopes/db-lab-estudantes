<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /**
     * Uma rota aceita dois formatos de action:
     *   - array{0: class-string<Controller>, 1: string} — controller "clássico" multi-ação,
     *     chamado como (new $classe())->$metodo($params). Ainda o formato certo pra recurso
     *     com várias operações relacionadas (ex.: AdminController).
     *   - class-string<Action> — "Single Action": uma classe só, chamada como
     *     (new $classe())($params), via __invoke() (ver App\Core\Action).
     *
     * @var array<string, list<array{pattern:string, params:list<string>, action:array{0:class-string<Controller>,1:string}|class-string<Action>}>>
     */
    private array $routes = [];

    /** @param array{0:class-string<Controller>,1:string}|class-string<Action> $action */
    public function get(string $path, array|string $action): void
    {
        $this->add('GET', $path, $action);
    }

    /** @param array{0:class-string<Controller>,1:string}|class-string<Action> $action */
    public function post(string $path, array|string $action): void
    {
        $this->add('POST', $path, $action);
    }

    /** @param array{0:class-string<Controller>,1:string}|class-string<Action> $action */
    private function add(string $method, string $path, array|string $action): void
    {
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $matches);
        $pattern = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '([^/]+)', $path);

        $this->routes[$method][] = [
            'pattern' => "#^{$pattern}$#",
            'params' => $matches[1],
            'action' => $action,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        foreach ($this->routes[$method] ?? [] as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            array_shift($matches);
            /** @var array<string,string> $params */
            $params = array_combine($route['params'], $matches);

            $action = $route['action'];
            if (is_string($action)) {
                (new $action())($params);

                return;
            }

            [$controllerClass, $methodName] = $action;
            (new $controllerClass())->$methodName($params);
            return;
        }

        http_response_code(404);
        echo View::render('errors/404');
    }
}
