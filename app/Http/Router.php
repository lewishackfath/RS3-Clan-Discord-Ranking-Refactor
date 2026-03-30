<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

final class Router
{
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function __construct(private readonly string $basePath) {}

    public function get(string $path, Closure|array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, Closure|array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->{$action}($request);
            return;
        }

        $handler($request);
    }
}
