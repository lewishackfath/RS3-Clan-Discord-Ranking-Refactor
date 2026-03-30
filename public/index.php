<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Http\Middleware\InstallGuard;
use App\Http\Request;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
assert($app instanceof Bootstrap);

$guard = new InstallGuard($app);
$guard->enforce();

$router = $app->router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/install', [InstallController::class, 'index']);
$router->post('/install', [InstallController::class, 'store']);
$router->dispatch(new Request());
