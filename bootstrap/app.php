<?php

declare(strict_types=1);

use App\Bootstrap;

require_once __DIR__ . '/autoload.php';

return Bootstrap::create(basePath: dirname(__DIR__));
