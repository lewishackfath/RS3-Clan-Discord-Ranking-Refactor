<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static function load(string $basePath): array
    {
        $path = rtrim($basePath, '/') . '/config/config.php';

        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }
}
