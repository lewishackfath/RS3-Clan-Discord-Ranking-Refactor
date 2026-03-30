<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Install\InstallState;

final class HomeController
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function index(): void
    {
        $state = new InstallState($this->basePath);
        if (!$state->isInstalled()) {
            redirect('/install');
        }

        $config = require $this->basePath . '/config/config.php';
        $appName = $config['app']['name'] ?? 'RS3 Clan Discord Ranking App';

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($appName) . '</title><style>body{font-family:Arial,sans-serif;background:#111827;color:#f9fafb;padding:40px} .card{max-width:900px;margin:0 auto;background:#1f2937;border:1px solid #374151;border-radius:16px;padding:24px} a{color:#93c5fd}</style></head><body><div class="card"><h1>' . e($appName) . '</h1><p>Application installed successfully.</p><p>Phase 2 is complete. The Discord-authenticated admin flow can be layered on next.</p><p><a href="/install/admin-bootstrap">View first-admin bootstrap status</a></p></div></body></html>';
    }
}
