<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Install\InstallState;
use App\Support\Autoloader;

$basePath = dirname(__DIR__);

$helpersPath = $basePath . '/app/Support/helpers.php';
$autoloaderPath = $basePath . '/app/Support/Autoloader.php';

if (is_file($helpersPath)) {
    require_once $helpersPath;
} else {
    if (!function_exists('redirect')) {
        function redirect(string $url): never
        {
            header('Location: ' . $url);
            exit;
        }
    }
    if (!function_exists('e')) {
        function e(?string $value): string
        {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            $base = dirname(__DIR__);
            return $path !== '' ? $base . '/' . ltrim($path, '/') : $base;
        }
    }
}

if (!is_file($autoloaderPath)) {
    http_response_code(500);
    echo 'Missing required file: app/Support/Autoloader.php';
    exit;
}

require_once $autoloaderPath;

Autoloader::register($basePath);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$bootstrap = new Bootstrap($basePath);
$installState = new InstallState($bootstrap->basePath());

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isInstallRoute = str_starts_with($path, '/install');

if (!$installState->isInstalled() && !$isInstallRoute) {
    redirect('/install');
}

if ($installState->isInstalled() && $path === '/install') {
    redirect('/');
}

$router = $bootstrap->router();
$router->dispatch($method, $path);
