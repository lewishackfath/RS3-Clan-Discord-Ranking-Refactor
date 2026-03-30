<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function view(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        extract($data, EXTR_SKIP);

        $viewFile = dirname(__DIR__) . '/Views/' . $view . '.php';
        $layoutFile = dirname(__DIR__) . '/Views/' . $layout . '.php';

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require $layoutFile;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }
}
