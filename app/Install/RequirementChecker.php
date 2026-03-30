<?php

declare(strict_types=1);

namespace App\Install;

final class RequirementChecker
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function check(): array
    {
        $configDir = $this->basePath . '/config';
        $storageDir = $this->basePath . '/storage/install';

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $checks = [
            [
                'key' => 'php_version',
                'label' => 'PHP 8.2+',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'meta' => PHP_VERSION,
            ],
            [
                'key' => 'pdo',
                'label' => 'PDO extension',
                'ok' => extension_loaded('pdo'),
                'meta' => extension_loaded('pdo') ? 'loaded' : 'missing',
            ],
            [
                'key' => 'pdo_mysql',
                'label' => 'pdo_mysql extension',
                'ok' => extension_loaded('pdo_mysql'),
                'meta' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
            ],
            [
                'key' => 'json',
                'label' => 'JSON extension',
                'ok' => extension_loaded('json'),
                'meta' => extension_loaded('json') ? 'loaded' : 'missing',
            ],
            [
                'key' => 'session',
                'label' => 'Session extension',
                'ok' => extension_loaded('session'),
                'meta' => extension_loaded('session') ? 'loaded' : 'missing',
            ],
            [
                'key' => 'config_dir_writable',
                'label' => 'Config directory writable',
                'ok' => is_dir($configDir) && is_writable($configDir),
                'meta' => $configDir,
            ],
            [
                'key' => 'storage_dir_writable',
                'label' => 'Storage/install writable',
                'ok' => is_dir($storageDir) && is_writable($storageDir),
                'meta' => $storageDir,
            ],
        ];

        return [
            'ok' => !in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
    }
}
