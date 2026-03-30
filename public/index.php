<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Install\InstallState;
use App\Support\Autoloader;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$basePath = dirname(__DIR__);

$helpersPath = $basePath . '/app/Support/helpers.php';
$autoloaderPath = $basePath . '/app/Support/Autoloader.php';

if (is_file($helpersPath)) {
    require_once $helpersPath;
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path !== '' ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        return $_SESSION['_old'][$key] ?? $default;
    }
}

if (!function_exists('session_flash')) {
    function session_flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('session_get_flash')) {
    function session_get_flash(string $key, mixed $default = null): mixed
    {
        if (!isset($_SESSION['_flash'][$key])) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}

if (!function_exists('session_old_input')) {
    function session_old_input(array $input): void
    {
        $_SESSION['_old'] = $input;
    }
}

if (!function_exists('session_clear_old_input')) {
    function session_clear_old_input(): void
    {
        unset($_SESSION['_old']);
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf'])
            && is_string($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || $_SESSION['_csrf'] == '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!is_file($autoloaderPath)) {
    http_response_code(500);
    echo 'Missing required file: app/Support/Autoloader.php';
    exit;
}

require_once $autoloaderPath;

Autoloader::register($basePath);

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
