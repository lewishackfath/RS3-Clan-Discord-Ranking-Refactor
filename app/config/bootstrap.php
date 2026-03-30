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
session_name($sessionName);
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
