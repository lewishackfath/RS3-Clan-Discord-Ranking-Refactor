<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    public function index(Request $request): void
    {
        Response::view('home/index', [
            'title' => 'Dashboard',
        ]);
    }
}
