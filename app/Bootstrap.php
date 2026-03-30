<?php

declare(strict_types=1);

namespace App;

use App\Config\Config;
use App\Database\Connection;
use App\Database\Database;
use App\Http\Router;
use App\Repositories\SettingsRepository;

final class Bootstrap
{
    private ?Database $db = null;
    private ?SettingsRepository $settings = null;

    public function __construct(
        private readonly string $basePath,
        private readonly Config $config,
        private readonly Router $router,
    ) {}

    public static function create(string $basePath): self
    {
        $config = new Config($basePath);
        $router = new Router($basePath);

        return new self($basePath, $config, $router);
    }

    public function basePath(string $path = ''): string
    {
        $full = rtrim($this->basePath, '/');

        if ($path !== '') {
            $full .= '/' . ltrim($path, '/');
        }

        return $full;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Database
    {
        if ($this->db === null) {
            $pdo = Connection::make($this->config->get('database'));
            $this->db = new Database($pdo);
        }

        return $this->db;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function settings(): SettingsRepository
    {
        if ($this->settings === null) {
            $this->settings = new SettingsRepository($this->db());
        }

        return $this->settings;
    }
}