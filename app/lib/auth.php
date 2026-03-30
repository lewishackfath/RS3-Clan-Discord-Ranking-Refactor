<?php
declare(strict_types=1);

function require_login(): void
{
    if (empty($_SESSION['admin_user'])) {
        redirect('/auth/login.php');
    }
}

function current_admin(): ?array
{
    return $_SESSION['admin_user'] ?? null;
}

function clear_admin_session(): void
{
    unset($_SESSION['admin_user'], $_SESSION['oauth_access_token']);
}

function is_admin_authorised(array $guildMember, array $guildRoles, ?string $candidateUserId = null): bool
{
    $userId = $candidateUserId;

    if ($userId === null || $userId === '') {
        $admin = current_admin();
        if (!$admin) {
            return false;
        }
        $userId = (string)($admin['id'] ?? '');
    }

    $allowedUsers = csv_ids((string)env('ADMIN_DISCORD_USER_IDS', ''));
    if ($allowedUsers && in_array($userId, $allowedUsers, true)) {
        return true;
    }

    $allowedRoles = csv_ids((string)env('ADMIN_DISCORD_ROLE_IDS', ''));
    if ($allowedRoles) {
        foreach (($guildMember['roles'] ?? []) as $roleId) {
            if (in_array((string)$roleId, $allowedRoles, true)) {
                return true;
            }
        }
        return false;
    }

    return true;
}