<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/config/bootstrap.php';

try {
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($state === '' || $code === '') {
        throw new RuntimeException('Missing OAuth code or state.');
    }

    $expectedState = (string)($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state']);
    if ($expectedState === '' || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Invalid OAuth state.');
    }

    $token = discord_exchange_code($code);
    $accessToken = (string)($token['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Discord did not return an access token.');
    }

    $user = discord_get_oauth_user($accessToken);
    $guildId = (string)env('DISCORD_GUILD_ID', '');
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    $guildMember = discord_get_guild_member($guildId, (string)$user['id']);
    if ($guildMember === null) {
        throw new RuntimeException('Your Discord account is not a member of the configured server.');
    }

    $guildRoles = discord_get_guild_roles($guildId);
    if (!is_admin_authorised($guildMember, $guildRoles, (string)$user['id'])) {
        throw new RuntimeException('Your Discord account is not authorised for this admin interface.');
    }

    $_SESSION['admin_user'] = [
        'id' => (string)$user['id'],
        'username' => (string)(($user['global_name'] ?? '') !== '' ? $user['global_name'] : ($user['username'] ?? 'Unknown')),
        'raw_username' => (string)($user['username'] ?? ''),
        'avatar' => (string)($user['avatar'] ?? ''),
    ];
    $_SESSION['oauth_access_token'] = $accessToken;

    flash('success', 'Signed in successfully.');
    redirect('/admin/index.php');
} catch (Throwable $e) {
    clear_admin_session();
    flash('error', $e->getMessage());
    redirect('/auth/login.php');
}