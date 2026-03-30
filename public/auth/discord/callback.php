<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/config/bootstrap.php';

try {
    $state = $_GET['state'] ?? '';
    $code = $_GET['code'] ?? '';

    if (!$state || !$code || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
        throw new RuntimeException('Invalid OAuth state');
    }
    unset($_SESSION['oauth_state']);

    $token = discord_exchange_code($code);
    $user = discord_get_oauth_user($token['access_token']);

    $serviceResponse = bot_service_request('/guild/member/' . $user['id']);
    if (($serviceResponse['status'] ?? 500) !== 200 || !is_array($serviceResponse['json'])) {
        throw new RuntimeException('Could not verify your guild membership through the bot service.');
    }

    $member = $serviceResponse['json'];
    if (!current_admin_can_manage($member['role_ids'] ?? [])) {
        throw new RuntimeException('Your Discord account is not authorised to access this admin interface.');
    }

    $_SESSION['admin_user'] = [
        'id' => (string)$user['id'],
        'username' => $user['username'] ?? '',
        'global_name' => $user['global_name'] ?? '',
        'avatar' => $user['avatar'] ?? '',
        'guild_member' => $member,
    ];

    flash('success', 'Signed in successfully.');
    redirect('/admin/index.php');
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect('/auth/login.php');
}
