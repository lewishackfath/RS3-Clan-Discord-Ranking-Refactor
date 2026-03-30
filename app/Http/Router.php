<?php

declare(strict_types=1);

namespace App\Http;

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly string $basePath)
    {
        $this->mapRoutes();
    }

    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        [$class, $action] = $handler;
        $controller = new $class($this->basePath);
        $controller->{$action}();
    }

    private function mapRoutes(): void
    {
        $this->get('/', [HomeController::class, 'index']);

        $this->get('/install', [InstallController::class, 'index']);
        $this->post('/install', [InstallController::class, 'store']);
        $this->get('/install/success', [InstallController::class, 'success']);

        $this->get('/install/admin-bootstrap', [AuthController::class, 'bootstrapAdmin']);
    }
}
