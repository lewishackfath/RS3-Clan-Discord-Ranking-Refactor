<?php

use App\Support\Env;

return [
    'driver' => 'mysql',
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => Env::get('DB_NAME', ''),
    'username' => Env::get('DB_USER', ''),
    'password' => Env::get('DB_PASS', ''),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',
];
