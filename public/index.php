<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Install\InstallState;
use App\Support\Autoloader;

$basePath = dirname(__DIR__);

require $basePath . '/app/Support/helpers.php';
require $basePath . '/app/Support/Autoloader.php';

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
