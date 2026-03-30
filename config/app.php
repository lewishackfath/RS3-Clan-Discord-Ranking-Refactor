<?php

use App\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'RS3 Clan Discord Ranking App'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'timezone' => Env::get('APP_TIMEZONE', 'Australia/Sydney'),
];
