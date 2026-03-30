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
    public function __construct(
        private readonly string $basePath,
        private readonly Config $config,
        private readonly Database $db,
        private readonly Router $router,
        private readonly SettingsRepository $settings,
    ) {}

    public static function create(string $basePath): self
    {
        $config = new Config($basePath);
        $pdo = Connection::make($config->get('database'));
        $db = new Database($pdo);
        $router = new Router($basePath);
        $settings = new SettingsRepository($db);

        return new self($basePath, $config, $db, $router, $settings);
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
        return $this->db;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function settings(): SettingsRepository
    {
        return $this->settings;
    }
}
