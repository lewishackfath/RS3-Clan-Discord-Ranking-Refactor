<?php

declare(strict_types=1);

namespace App;

use App\Database\Connection;
use App\Http\Router;

final class Bootstrap
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function router(): Router
    {
        return new Router($this->basePath);
    }

    public function db(): \PDO
    {
        return Connection::make($this->basePath);
    }
}
