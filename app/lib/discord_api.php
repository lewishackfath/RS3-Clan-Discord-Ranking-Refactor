<?php
declare(strict_types=1);

function discord_http(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $ch = curl_init($url);
    $defaultHeaders = [
        'Accept: application/json',
        'User-Agent: RS3ClanRanker/1.0',
    ];

    if ($json !== null) {
        $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $defaultHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Discord request failed: ' . $error);
    }

    $decoded = json_decode($body, true);
    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function discord_bot_headers(): array
{
    return ['Authorization: Bot ' . env('DISCORD_BOT_TOKEN', '')];
}

function discord_oauth_headers(string $token): array
{
    return ['Authorization: Bearer ' . $token];
}

function discord_exchange_code(string $code): array
{
    $post = http_build_query([
        'client_id' => env('DISCORD_CLIENT_ID', ''),
        'client_secret' => env('DISCORD_CLIENT_SECRET', ''),
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => env('DISCORD_REDIRECT_URI', ''),
    ]);

    $ch = curl_init('https://discord.com/api/v10/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('OAuth token exchange failed: ' . $error);
    }

    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        throw new RuntimeException('OAuth token exchange failed with HTTP ' . $status . ': ' . $body);
    }

    return $json;
}

function discord_get_oauth_user(string $accessToken): array
{
    $response = discord_http('GET', 'https://discord.com/api/v10/users/@me', null, discord_oauth_headers($accessToken));
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch Discord user');
    }
    return $response['json'];
}

function discord_get_oauth_guilds(string $accessToken): array
{
    $response = discord_http('GET', 'https://discord.com/api/v10/users/@me/guilds', null, discord_oauth_headers($accessToken));
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch Discord guilds');
    }
    return $response['json'];
}

function bot_service_request(string $path, string $method = 'GET', ?array $json = null): array
{
    $url = rtrim(bot_api_base(), '/') . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'X-Bot-Shared-Secret: ' . env('BOT_SHARED_SECRET', ''),
    ];

    return discord_http($method, $url, $json, $headers);
}
