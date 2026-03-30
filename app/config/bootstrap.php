<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/env.php';
load_env(dirname(__DIR__, 2) . '/.env');

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/discord_api.php';

$timezone = env('TIMEZONE', 'Australia/Sydney') ?: 'Australia/Sydney';
date_default_timezone_set($timezone);

$sessionName = env('SESSION_NAME', 'rs3_ranker_admin') ?: 'rs3_ranker_admin';
$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string)env('APP_URL', ''), 'https://');

ini_set('session.use_strict_mode', '1');
session_name($sessionName);
session_set_cookie_params([
    'httponly' => true,
    'secure' => $cookieSecure,
    'samesite' => 'Lax',
    'path' => '/',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
