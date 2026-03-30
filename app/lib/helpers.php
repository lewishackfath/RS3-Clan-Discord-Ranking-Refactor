<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function post_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_or_fail(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$session || !hash_equals($session, $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

function normalise_rsn(string $value): string
{
    $value = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', ' ', $value) ?? $value;
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = strtolower($value);
    $value = str_replace('_', ' ', $value);
    return trim($value);
}

function csv_ids(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts, static fn($v) => $v !== ''));
}

function bot_api_base(): string
{
    return 'http://' . env('BOT_HOST', '127.0.0.1') . ':' . env('BOT_PORT', '3100');
}

function app_url(string $path = ''): string
{
    $base = rtrim(env('APP_URL', ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}
