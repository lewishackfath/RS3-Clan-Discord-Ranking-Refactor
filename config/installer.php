<?php

use App\Support\Env;

return [
    'enabled' => filter_var(Env::get('INSTALL_ENABLED', true), FILTER_VALIDATE_BOOL),
    'lock_file' => 'storage/install/installed.lock',
];
