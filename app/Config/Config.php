<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Env;
use RuntimeException;

final class Config
{
    private array $items = [];

    public function __construct(private readonly string $basePath)
    {
        Env::load($this->basePath . '/.env');
        $this->loadConfigFiles();
        date_default_timezone_set((string) $this->get('app.timezone', 'Australia/Sydney'));
    }

    private function loadConfigFiles(): void
    {
        $configDir = $this->basePath . '/config';
        $files = glob($configDir . '/*.php') ?: [];

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $data = require $file;

            if (!is_array($data)) {
                throw new RuntimeException("Config file [{$key}] must return an array.");
            }

            $this->items[$key] = $data;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
