<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Database\SchemaInstaller;
use App\Http\Middleware\InstallGuard;
use App\Http\Request;
use App\Http\Response;

final class InstallController
{
    public function index(Request $request): void
    {
        Response::view('install/index', [
            'title' => 'Install Application',
        ]);
    }

    public function store(Request $request): void
    {
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        assert($app instanceof Bootstrap);

        $installer = new SchemaInstaller($app->db(), $app->basePath('database/schema.sql'));
        $installer->install();

        $app->settings()->set('app.installed', '1');
        $app->settings()->set('app.version', '1.0.0');
        $app->settings()->set('app.name', (string) $app->config()->get('app.name'));

        $app->db()->execute(
            'INSERT INTO installs (app_version, installed_by_ip) VALUES (:version, :ip)',
            [
                'version' => '1.0.0',
                'ip' => $request->ip(),
            ]
        );

        $guard = new InstallGuard($app);
        $guard->writeLock();

        Response::redirect('/');
    }
}
