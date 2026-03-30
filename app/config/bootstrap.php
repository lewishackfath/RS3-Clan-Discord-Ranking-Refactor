<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/discord_api.php';
require_once __DIR__ . '/../lib/auth.php';

load_env(dirname(__DIR__, 2) . '/.env');

date_default_timezone_set(env('TIMEZONE', 'Australia/Sydney'));

$sessionName = env('SESSION_NAME', 'rs3_ranker_admin');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($sessionName);
    session_start();
}

$GLOBALS['db'] = db();
