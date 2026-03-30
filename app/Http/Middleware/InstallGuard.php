<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Bootstrap;

final class InstallGuard
{
    public function __construct(private readonly Bootstrap $app) {}

    public function installed(): bool
    {
        $lockPath = $this->app->basePath(
            (string) $this->app->config()->get('installer.lock_file', 'storage/install/installed.lock')
        );

        if (is_file($lockPath)) {
            return true;
        }

        try {
            return (bool) $this->app->settings()->get('app.installed', false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function enforce(): void
    {
        if (!$this->app->config()->get('installer.enabled', true)) {
            return;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $installPrefix = '/install';

        if (!$this->installed() && !str_starts_with($path, $installPrefix)) {
            header('Location: /install');
            exit;
        }

        if ($this->installed() && str_starts_with($path, $installPrefix)) {
            header('Location: /');
            exit;
        }
    }

    public function writeLock(): void
    {
        $lockPath = $this->app->basePath(
            (string) $this->app->config()->get('installer.lock_file', 'storage/install/installed.lock')
        );

        $dir = dirname($lockPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($lockPath, 'installed=' . gmdate('c'));
    }
}
