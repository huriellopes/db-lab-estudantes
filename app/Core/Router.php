<?php

namespace App\Core;

class Router
{
    /** @var array<string, list<array{pattern:string, params:list<string>, action:array{0:class-string,1:string}}>> */
    private array $routes = [];

    public function get(string $path, array $action): void
    {
        $this->add('GET', $path, $action);
    }

    public function post(string $path, array $action): void
    {
        $this->add('POST', $path, $action);
    }

    private function add(string $method, string $path, array $action): void
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
            $params = array_combine($route['params'], $matches);

            [$controllerClass, $methodName] = $route['action'];
            (new $controllerClass())->$methodName($params);
            return;
        }

        http_response_code(404);
        echo View::render('errors/404');
    }
}
