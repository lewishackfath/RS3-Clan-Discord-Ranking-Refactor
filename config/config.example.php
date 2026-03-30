<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'RS3 Clan Discord Ranking App',
        'url' => 'https://your-domain.example.com',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Australia/Sydney',
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'your_database',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset' => 'utf8mb4',
    ],
    'discord' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
        'bot_token' => '',
        'guild_id' => '',
    ],
];
