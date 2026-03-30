<?php

declare(strict_types=1);

namespace App\Install;

final class InstallState
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->configPath()) && is_file($this->lockPath());
    }

    public function writeLock(array $payload = []): void
    {
        $dir = dirname($this->lockPath());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create install directory.');
        }

        $body = json_encode([
            'installed_at_utc' => gmdate('c'),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->lockPath(), $body . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write install lock file.');
        }
    }

    public function configPath(): string
    {
        return $this->basePath . '/config/config.php';
    }

    public function lockPath(): string
    {
        return $this->basePath . '/storage/install/installed.lock';
    }
}
