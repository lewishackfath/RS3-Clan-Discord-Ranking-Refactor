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

function current_admin_can_manage(array $guildMemberRoleIds): bool
{
    $admin = current_admin();
    if (!$admin) {
        return false;
    }

    $allowedUsers = csv_ids(env('ADMIN_DISCORD_USER_IDS', ''));
    if ($allowedUsers && in_array((string)$admin['id'], $allowedUsers, true)) {
        return true;
    }

    $allowedRoles = csv_ids(env('ADMIN_DISCORD_ROLE_IDS', ''));
    if (!$allowedRoles) {
        return true;
    }

    foreach ($guildMemberRoleIds as $roleId) {
        if (in_array((string)$roleId, $allowedRoles, true)) {
            return true;
        }
    }

    return false;
}
