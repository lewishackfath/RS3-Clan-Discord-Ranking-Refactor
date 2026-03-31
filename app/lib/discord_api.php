<?php
declare(strict_types=1);

const DISCORD_API_BASE = 'https://discord.com/api/v10';

function discord_request(string $method, string $path, ?array $json = null, array $headers = [], bool $formEncoded = false): array
{
    $url = str_starts_with($path, 'http') ? $path : DISCORD_API_BASE . $path;
    $ch = curl_init($url);
    $defaultHeaders = [
        'Accept: application/json',
        'User-Agent: RS3ClanRanker/1.0',
    ];

    if ($json !== null) {
        if ($formEncoded) {
            $payload = http_build_query($json);
            $defaultHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $defaultHeaders[] = 'Content-Type: application/json';
        }
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
    $token = (string)env('DISCORD_BOT_TOKEN', '');
    return ['Authorization: Bot ' . $token];
}

function discord_oauth_headers(string $token): array
{
    return ['Authorization: Bearer ' . $token];
}

function discord_exchange_code(string $code): array
{
    $response = discord_request('POST', '/oauth2/token', [
        'client_id' => env('DISCORD_CLIENT_ID', ''),
        'client_secret' => env('DISCORD_CLIENT_SECRET', ''),
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => env('DISCORD_REDIRECT_URI', ''),
    ], [], true);

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('OAuth token exchange failed with HTTP ' . $response['status'] . ': ' . $response['body']);
    }

    return $response['json'];
}

function discord_get_oauth_user(string $accessToken): array
{
    $response = discord_request('GET', '/users/@me', null, discord_oauth_headers($accessToken));
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch Discord user via OAuth.');
    }
    return $response['json'];
}

function discord_get_bot_user(): array
{
    $response = discord_request('GET', '/users/@me', null, discord_bot_headers());
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch bot identity. Check DISCORD_BOT_TOKEN.');
    }
    return $response['json'];
}

function discord_get_guild(string $guildId): array
{
    $response = discord_request('GET', '/guilds/' . rawurlencode($guildId), null, discord_bot_headers());
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch guild. Ensure the bot is added to the server and DISCORD_GUILD_ID is correct.');
    }
    return $response['json'];
}

function discord_get_guild_roles(string $guildId): array
{
    $response = discord_request('GET', '/guilds/' . rawurlencode($guildId) . '/roles', null, discord_bot_headers());
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch guild roles.');
    }
    return $response['json'];
}



function discord_get_guild_channels(string $guildId): array
{
    $response = discord_request('GET', '/guilds/' . rawurlencode($guildId) . '/channels', null, discord_bot_headers());
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch guild channels.');
    }
    return $response['json'];
}

function discord_get_guild_text_channels(string $guildId): array
{
    $channels = discord_get_guild_channels($guildId);
    $textChannels = [];
    foreach ($channels as $channel) {
        $type = (int)($channel['type'] ?? -1);
        if (in_array($type, [0, 5], true)) {
            $textChannels[] = $channel;
        }
    }

    usort($textChannels, static function (array $a, array $b): int {
        $aPos = (int)($a['position'] ?? 0);
        $bPos = (int)($b['position'] ?? 0);
        if ($aPos !== $bPos) {
            return $aPos <=> $bPos;
        }
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $textChannels;
}

function discord_get_guild_member(string $guildId, string $userId): ?array
{
    $response = discord_request('GET', '/guilds/' . rawurlencode($guildId) . '/members/' . rawurlencode($userId), null, discord_bot_headers());
    if ($response['status'] === 404) {
        return null;
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to fetch guild member ' . $userId . '.');
    }
    return $response['json'];
}

function discord_list_guild_members(string $guildId, int $limit = 1000): array
{
    $members = [];
    $after = '0';

    do {
        $path = sprintf('/guilds/%s/members?limit=%d&after=%s', rawurlencode($guildId), $limit, rawurlencode($after));
        $response = discord_request('GET', $path, null, discord_bot_headers());
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            throw new RuntimeException('Failed to list guild members.');
        }

        $batch = $response['json'];
        foreach ($batch as $member) {
            if (!is_array($member)) {
                continue;
            }
            $members[] = $member;
        }

        if (!$batch) {
            break;
        }

        $last = end($batch);
        $after = (string)($last['user']['id'] ?? '0');
    } while (count($batch) === $limit && $after !== '0');

    return $members;
}

function discord_create_role(string $guildId, string $name): array
{
    $response = discord_request('POST', '/guilds/' . rawurlencode($guildId) . '/roles', [
        'name' => $name,
        'mentionable' => false,
        'hoist' => false,
    ], discord_bot_headers());

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to create role. Ensure the bot has Manage Roles permission and sufficient hierarchy.');
    }

    return $response['json'];
}

function discord_role_map(array $roles): array
{
    $map = [];
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        $map[(string)$role['id']] = $role;
    }
    return $map;
}

function discord_role_positions(array $roles): array
{
    $positions = [];
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        $positions[(string)$role['id']] = (int)($role['position'] ?? 0);
    }
    return $positions;
}

function discord_member_highest_role(array $member, array $rolePositions): ?array
{
    $highestRoleId = null;
    $highestPosition = -1;

    foreach (($member['roles'] ?? []) as $roleId) {
        $roleId = (string)$roleId;
        $position = $rolePositions[$roleId] ?? -1;
        if ($position > $highestPosition) {
            $highestPosition = $position;
            $highestRoleId = $roleId;
        }
    }

    if ($highestRoleId === null) {
        return null;
    }

    return ['id' => $highestRoleId, 'position' => $highestPosition];
}

function discord_format_member_summary(array $member): array
{
    $user = $member['user'] ?? [];
    $username = (string)($user['username'] ?? 'unknown');
    $globalName = (string)($user['global_name'] ?? '');
    $nickname = (string)($member['nick'] ?? '');

    return [
        'user_id' => (string)($user['id'] ?? ''),
        'username' => $username,
        'display_name' => $globalName !== '' ? $globalName : $username,
        'nickname' => $nickname,
        'roles' => array_map('strval', $member['roles'] ?? []),
        'avatar_url' => discord_avatar_url($user),
    ];
}

function validate_bot_readiness(string $guildId, array $mappedRoleIds = [], array $botRoleFlagIds = []): array
{
    $guild = discord_get_guild($guildId);
    $roles = discord_get_guild_roles($guildId);
    $rolePositions = discord_role_positions($roles);
    $roleMap = discord_role_map($roles);

    $botUser = discord_get_bot_user();
    $botMember = discord_get_guild_member($guildId, (string)$botUser['id']);
    if ($botMember === null) {
        throw new RuntimeException('The bot user is not present in the configured guild.');
    }

    $botHighest = discord_member_highest_role($botMember, $rolePositions);
    $maxServerRole = null;
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        if ((string)($role['name'] ?? '') === '@everyone') {
            continue;
        }
        if ($maxServerRole === null || (int)$role['position'] > (int)$maxServerRole['position']) {
            $maxServerRole = $role;
        }
    }

    $messages = [];
    $ok = true;

    if ($botHighest === null) {
        $ok = false;
        $messages[] = 'The bot has no guild role assigned.';
    } else {
        if ($maxServerRole && (int)$maxServerRole['position'] > (int)$botHighest['position']) {
            $ok = false;
            $messages[] = sprintf(
                'Bot role is not the highest role in the server. Highest server role: %s. Bot highest role: %s.',
                (string)$maxServerRole['name'],
                (string)($roleMap[$botHighest['id']]['name'] ?? $botHighest['id'])
            );
        }

        $botMemberRoleIds = array_map('strval', $botMember['roles'] ?? []);
        $requiredRoleIds = array_values(array_unique(array_filter(array_merge($mappedRoleIds, $botRoleFlagIds))));
        foreach ($requiredRoleIds as $roleId) {
            $roleId = (string)$roleId;
            $role = $roleMap[$roleId] ?? null;
            if (!$role) {
                continue;
            }

            // Ignore the bot's own roles here. Discord only requires the bot to sit above
            // target roles it is expected to manage; it does not need to sit above itself.
            if ($roleId === (string)$botHighest['id'] || in_array($roleId, $botMemberRoleIds, true)) {
                continue;
            }

            if ((int)$role['position'] >= (int)$botHighest['position']) {
                $ok = false;
                $messages[] = sprintf('Bot role must sit above role "%s".', (string)$role['name']);
            }
        }
    }

    return [
        'ok' => $ok,
        'guild' => $guild,
        'roles' => $roles,
        'role_map' => $roleMap,
        'role_positions' => $rolePositions,
        'bot_user' => $botUser,
        'bot_member' => $botMember,
        'bot_highest_role' => $botHighest,
        'max_server_role' => $maxServerRole,
        'messages' => $messages,
    ];
}


function discord_modify_member_roles(string $guildId, string $userId, array $roleIds): void
{
    $roleIds = array_values(array_unique(array_map('strval', array_filter($roleIds, static fn($v): bool => (string)$v !== ''))));
    $response = discord_request('PATCH', '/guilds/' . rawurlencode($guildId) . '/members/' . rawurlencode($userId), [
        'roles' => $roleIds,
    ], discord_bot_headers());

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Failed to update roles for guild member ' . $userId . '.');
    }
}

function discord_create_dm_channel(string $userId): string
{
    $response = discord_request('POST', '/users/@me/channels', [
        'recipient_id' => $userId,
    ], discord_bot_headers());

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
        throw new RuntimeException('Failed to create DM channel for user ' . $userId . '.');
    }

    return (string)($response['json']['id'] ?? '');
}

function discord_send_channel_message(string $channelId, string $content): void
{
    $response = discord_request('POST', '/channels/' . rawurlencode($channelId) . '/messages', [
        'content' => $content,
    ], discord_bot_headers());

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Failed to send Discord message to channel ' . $channelId . '.');
    }
}

function discord_send_dm(string $userId, string $content): void
{
    $channelId = discord_create_dm_channel($userId);
    if ($channelId === '') {
        throw new RuntimeException('Failed to create DM channel for user ' . $userId . '.');
    }
    discord_send_channel_message($channelId, $content);
}
