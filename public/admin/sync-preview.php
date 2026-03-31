<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$requiredTables = ['rs_rank_mappings', 'discord_role_flags', 'discord_user_mappings', 'clan_members', 'guild_settings'];
$missingTables = require_tables($pdo, $requiredTables);

function sync_status_label(string $status): array
{
    return match ($status) {
        'ready_change' => ['Ready: Change', 'warn'],
        'ready_no_change' => ['Ready: No Change', 'ok'],
        'blocked_hierarchy' => ['Blocked: Hierarchy', 'bad'],
        'no_match' => ['No Match', 'bad'],
        'no_rank_mapping' => ['No Rank Mapping', 'warn'],
        default => [ucwords(str_replace('_', ' ', $status)), 'warn'],
    };
}

function role_chip_html(array $roles, array $classes = []): string
{
    if ($roles === []) {
        return '<span class="muted small">—</span>';
    }
    $html = '<div class="role-chip-wrap">';
    foreach ($roles as $role) {
        $class = trim('role-chip ' . implode(' ', $classes) . ' ' . (string)($role['extra_class'] ?? ''));
        $name = h((string)($role['name'] ?? 'Unknown'));
        $meta = '';
        if (!empty($role['meta'])) {
            $meta = ' <span class="muted small">(' . h((string)$role['meta']) . ')</span>';
        }
        $html .= '<span class="' . h($class) . '">' . $name . $meta . '</span>';
    }
    $html .= '</div>';
    return $html;
}

function preview_member_roles(array $roleIds, array $roleMap, array $roleFlags): array
{
    $roles = [];
    foreach ($roleIds as $roleId) {
        $roleId = (string)$roleId;
        if ($roleId === '' || !isset($roleMap[$roleId])) {
            continue;
        }
        $role = $roleMap[$roleId];
        if ((string)($role['name'] ?? '') === '@everyone') {
            continue;
        }
        $flag = $roleFlags[$roleId] ?? null;
        $extra = [];
        if (!empty($flag['is_bot_role'])) {
            $extra[] = 'bot';
        }
        if (!empty($flag['is_protected_role'])) {
            $extra[] = 'protected';
        }
        $roles[] = [
            'id' => $roleId,
            'name' => (string)$role['name'],
            'position' => (int)($role['position'] ?? 0),
            'extra_class' => implode(' ', $extra),
            'meta' => !empty($role['managed']) ? 'Discord managed' : '',
        ];
    }

    usort($roles, static fn(array $a, array $b): int => ($b['position'] ?? 0) <=> ($a['position'] ?? 0));
    return $roles;
}



function build_role_name_list(array $roleIds, array $roleMap): string
{
    $names = [];
    foreach ($roleIds as $roleId) {
        $role = $roleMap[(string)$roleId] ?? null;
        if (!$role) {
            continue;
        }
        $name = trim((string)($role['name'] ?? ''));
        if ($name === '' || $name === '@everyone') {
            continue;
        }
        $names[] = $name;
    }
    return $names ? implode(', ', $names) : 'None';
}

function maybe_log_member_change_embed(array $guildSettings, array $summaryMember, ?array $resolvedMember, string $rankName, array $addRoleIds, array $removeRoleIds, array $currentRoleIds, array $roleMap): void
{
    $logChannelId = trim((string)($guildSettings['log_channel_id'] ?? ''));
    if ($logChannelId === '') {
        return;
    }

    if ($addRoleIds === [] && $removeRoleIds === []) {
        return;
    }

    $displayName = (string)($summaryMember['nickname'] !== '' ? $summaryMember['nickname'] : $summaryMember['display_name']);
    $rsn = (string)($resolvedMember['rsn'] ?? '');
    $resolvedRank = $rankName !== '' ? $rankName : 'Guest';

    $embed = [
        'title' => 'Clan Roles Updated (' . ($displayName !== '' ? $displayName : (string)$summaryMember['username']) . ')',
        'color' => 5793266,
        'fields' => [
            [
                'name' => 'Current Rank',
                'value' => $resolvedRank,
                'inline' => true,
            ],
            [
                'name' => 'RuneScape Name',
                'value' => $rsn !== '' ? $rsn : 'Unmatched',
                'inline' => true,
            ],
            [
                'name' => 'Discord User',
                'value' => '<@' . (string)$summaryMember['user_id'] . '>',
                'inline' => true,
            ],
            [
                'name' => 'Roles Added',
                'value' => build_role_name_list($addRoleIds, $roleMap),
                'inline' => false,
            ],
            [
                'name' => 'Roles Removed',
                'value' => build_role_name_list($removeRoleIds, $roleMap),
                'inline' => false,
            ],
            [
                'name' => 'Current Roles',
                'value' => build_role_name_list($currentRoleIds, $roleMap),
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => 'RS3 Clan Ranker',
        ],
        'timestamp' => gmdate('c'),
    ];

    discord_send_channel_embed($logChannelId, $embed);
}

function render_guest_dm_template(string $template, array $context): string
{
    $replace = [];
    foreach ($context as $key => $value) {
        $replace['{' . $key . '}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function execute_manual_sync(PDO $pdo, string $guildId, int $clanId): string
{
    $missingApplyTables = require_tables($pdo, ['sync_runs', 'sync_run_members']);
    if ($missingApplyTables) {
        throw new RuntimeException('Manual sync tables are missing. Run sql/migrations/phase3.0-manual-apply-sync-audit-log.sql first.');
    }

    $guild = discord_get_guild($guildId);
    $guildName = (string)($guild['name'] ?? '');

    $guildRoles = discord_get_guild_roles($guildId);
    $roleMap = discord_role_map($guildRoles);
    $rolePositions = discord_role_positions($guildRoles);

    $botUser = discord_get_bot_user();
    $botMember = discord_get_guild_member($guildId, (string)$botUser['id']);
    if ($botMember === null) {
        throw new RuntimeException('The bot user is not present in the configured guild.');
    }

    $botHighestRole = discord_member_highest_role($botMember, $rolePositions);
    if ($botHighestRole === null) {
        throw new RuntimeException('The bot has no guild role assigned.');
    }

    $botRoleIds = array_map('strval', $botMember['roles'] ?? []);

    $roleFlags = [];
    $flagStmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
    $flagStmt->execute(['guild_id' => $guildId]);
    foreach ($flagStmt->fetchAll() as $row) {
        $roleFlags[(string)$row['discord_role_id']] = $row;
    }

    $rankMappings = [];
    $mapStmt = $pdo->prepare('SELECT rs_rank_name, discord_role_id, is_enabled FROM rs_rank_mappings WHERE clan_id = :clan_id ORDER BY rs_rank_name ASC, id ASC');
    $mapStmt->execute(['clan_id' => $clanId]);
    foreach ($mapStmt->fetchAll() as $row) {
        $rankName = (string)$row['rs_rank_name'];
        if (!isset($rankMappings[$rankName])) {
            $rankMappings[$rankName] = ['is_enabled' => (int)$row['is_enabled'] === 1, 'role_ids' => []];
        }
        $rankMappings[$rankName]['is_enabled'] = (int)$row['is_enabled'] === 1;
        $roleId = trim((string)($row['discord_role_id'] ?? ''));
        if ($roleId !== '') {
            $rankMappings[$rankName]['role_ids'][] = $roleId;
        }
    }

    $manualMappings = [];
    $manualStmt = $pdo->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id');
    $manualStmt->execute(['clan_id' => $clanId, 'guild_id' => $guildId]);
    foreach ($manualStmt->fetchAll() as $row) {
        $manualMappings[(string)$row['discord_user_id']] = $row;
    }

    $clanById = [];
    $clanByNormalised = [];
    $clanStmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id AND is_active = 1 ORDER BY rsn ASC');
    $clanStmt->execute(['clan_id' => $clanId]);
    foreach (($clanStmt->fetchAll() ?: []) as $member) {
        $clanById[(string)$member['id']] = $member;
        $norm = (string)$member['rsn_normalised'];
        if ($norm !== '' && !isset($clanByNormalised[$norm])) {
            $clanByNormalised[$norm] = $member;
        }
    }

    $settingsStmt = $pdo->prepare('SELECT * FROM guild_settings WHERE clan_id = :clan_id LIMIT 1');
    $settingsStmt->execute(['clan_id' => $clanId]);
    $guildSettings = $settingsStmt->fetch() ?: [];

    $admin = current_admin();
    $runStmt = $pdo->prepare('INSERT INTO sync_runs (
        clan_id, discord_guild_id, initiated_by_discord_user_id, initiated_by_name, status, started_at_utc, created_at, updated_at
    ) VALUES (
        :clan_id, :discord_guild_id, :initiated_by_discord_user_id, :initiated_by_name, :status, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
    )');
    $runStmt->execute([
        'clan_id' => $clanId,
        'discord_guild_id' => $guildId,
        'initiated_by_discord_user_id' => (string)($admin['id'] ?? '') !== '' ? (string)$admin['id'] : null,
        'initiated_by_name' => (string)($admin['username'] ?? 'Admin'),
        'status' => 'running',
    ]);
    $syncRunId = (int)$pdo->lastInsertId();

    $memberLogStmt = $pdo->prepare('INSERT INTO sync_run_members (
        sync_run_id, discord_user_id, discord_username, discord_display_name, resolved_rsn, resolved_rank_name, resolved_by,
        status, added_role_ids_csv, removed_role_ids_csv, blocked_role_ids_csv, guest_dm_attempted, guest_dm_success,
        guest_dm_error, notes, created_at, updated_at
    ) VALUES (
        :sync_run_id, :discord_user_id, :discord_username, :discord_display_name, :resolved_rsn, :resolved_rank_name, :resolved_by,
        :status, :added_role_ids_csv, :removed_role_ids_csv, :blocked_role_ids_csv, :guest_dm_attempted, :guest_dm_success,
        :guest_dm_error, :notes, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
    )');

    $counts = [
        'total' => 0,
        'changed' => 0,
        'skipped' => 0,
        'blocked' => 0,
        'errors' => 0,
        'guest_dm_sent' => 0,
        'guest_dm_failed' => 0,
    ];

    $discordMembers = discord_list_guild_members($guildId);
    foreach ($discordMembers as $discordMember) {
        if ((bool)($discordMember['user']['bot'] ?? false)) {
            continue;
        }

        $counts['total']++;
        $summaryMember = discord_format_member_summary($discordMember);
        $userId = (string)$summaryMember['user_id'];
        $manual = $manualMappings[$userId] ?? null;

        $resolvedMember = null;
        $resolvedBy = 'none';
        if ($manual && isset($clanById[(string)$manual['member_id']])) {
            $resolvedMember = $clanById[(string)$manual['member_id']];
            $resolvedBy = 'manual';
        } else {
            $fallbackMatch = resolve_clan_member_fallback(
                $clanByNormalised,
                [(string)$summaryMember['nickname'], (string)$summaryMember['display_name'], (string)$summaryMember['username']]
            );
            $resolvedMember = $fallbackMatch['member'] ?? null;
            $resolvedBy = $resolvedMember ? ('nickname_' . (string)($fallbackMatch['match_type'] ?? 'exact')) : (!empty($fallbackMatch['ambiguous']) ? 'ambiguous' : 'none');
        }

        $currentRoleIds = array_values(array_filter(array_map('strval', $discordMember['roles'] ?? []), static fn(string $id): bool => $id !== ''));
        $rankName = $resolvedMember ? (string)($resolvedMember['rank_name'] ?? '') : '';
        $resolvedIsGuest = !$resolvedMember;
        $targetRoleIds = [];

        $baseRowName = $resolvedIsGuest || strcasecmp($rankName, 'Guest') === 0 ? 'Guest' : 'Clan Member';
        $baseMappings = $rankMappings[$baseRowName]['role_ids'] ?? [];
        $baseEnabled = !empty($rankMappings[$baseRowName]['is_enabled']);
        if ($baseEnabled) {
            foreach ($baseMappings as $roleId) {
                $targetRoleIds[] = (string)$roleId;
            }
        }

        if ($resolvedMember) {
            $exactMappings = $rankMappings[$rankName]['role_ids'] ?? [];
            $exactEnabled = !empty($rankMappings[$rankName]['is_enabled']);
            if ($exactEnabled) {
                foreach ($exactMappings as $roleId) {
                    $targetRoleIds[] = (string)$roleId;
                }
            }
        }

        $targetRoleIds = array_values(array_unique(array_filter($targetRoleIds)));

        $addRoleIds = [];
        $removeRoleIds = [];
        $blockedRoleIds = [];
        $issues = [];
        $statusKey = 'ready_no_change';

        if ($resolvedBy === 'ambiguous') {
            $statusKey = 'ambiguous_match';
            $issues[] = 'Multiple RuneScape clan members partially matched this Discord name. Save a manual mapping to resolve it.';
        } elseif ($targetRoleIds === []) {
            $statusKey = 'no_rank_mapping';
            $issues[] = $resolvedMember
                ? 'The resolved RuneScape rank does not currently produce any target Discord roles.'
                : 'No RuneScape match was resolved, and the Guest mapping does not currently produce any target Discord roles.';
        } else {
            foreach ($targetRoleIds as $roleId) {
                if (!in_array($roleId, $currentRoleIds, true)) {
                    $addRoleIds[] = $roleId;
                }
                $role = $roleMap[$roleId] ?? null;
                if ($role && (int)($role['position'] ?? -1) >= (int)$botHighestRole['position']) {
                    $blockedRoleIds[] = $roleId;
                }
            }

            $managedRemovableRoleIds = [];
            foreach ($currentRoleIds as $roleId) {
                if (in_array($roleId, $botRoleIds, true)) {
                    continue;
                }
                $flag = $roleFlags[$roleId] ?? null;
                $isProtected = !empty($flag['is_protected_role']);
                $isBotFlag = !empty($flag['is_bot_role']);
                $isMappedRole = false;
                foreach ($rankMappings as $mappingRow) {
                    if (in_array($roleId, $mappingRow['role_ids'] ?? [], true)) {
                        $isMappedRole = true;
                        break;
                    }
                }
                if (($isBotFlag || $isMappedRole) && !$isProtected && !in_array($roleId, $targetRoleIds, true)) {
                    $managedRemovableRoleIds[] = $roleId;
                }
            }

            foreach ($managedRemovableRoleIds as $roleId) {
                $role = $roleMap[$roleId] ?? null;
                if ($role && (int)($role['position'] ?? -1) >= (int)$botHighestRole['position']) {
                    $blockedRoleIds[] = $roleId;
                }
                $removeRoleIds[] = $roleId;
            }

            if (!$resolvedMember) {
                $issues[] = 'No RuneScape clan member match was resolved. Guest fallback applied.';
            }

            $blockedRoleIds = array_values(array_unique($blockedRoleIds));
            if ($blockedRoleIds !== []) {
                $statusKey = 'blocked_hierarchy';
            } elseif ($addRoleIds !== [] || $removeRoleIds !== []) {
                $statusKey = 'ready_change';
            }
        }

        $memberStatus = 'skipped';
        $guestDmAttempted = 0;
        $guestDmSuccess = 0;
        $guestDmError = null;

        try {
            if ($statusKey === 'blocked_hierarchy') {
                $memberStatus = 'blocked_hierarchy';
                $counts['blocked']++;
            } elseif (in_array($statusKey, ['ambiguous_match', 'no_rank_mapping'], true)) {
                $memberStatus = $statusKey;
                $counts['skipped']++;
            } else {
                $newRoleIds = array_values(array_unique(array_merge(
                    array_values(array_diff($currentRoleIds, $removeRoleIds)),
                    $addRoleIds
                )));

                $sortedCurrent = $currentRoleIds;
                $sortedNew = $newRoleIds;
                sort($sortedCurrent);
                sort($sortedNew);

                if ($sortedCurrent !== $sortedNew) {
                    discord_modify_member_roles($guildId, $userId, $newRoleIds);
                    $memberStatus = 'changed';
                    $counts['changed']++;
                    try {
                        maybe_log_member_change_embed($guildSettings, $summaryMember, $resolvedMember, $rankName, $addRoleIds, $removeRoleIds, $newRoleIds, $roleMap);
                    } catch (Throwable $ignored) {
                    }
                } else {
                    $memberStatus = 'no_change';
                    $counts['skipped']++;
                }

                $guestFallbackChanged = !$resolvedMember && $memberStatus === 'changed';
                if ($guestFallbackChanged && !empty($guildSettings['send_guest_dm']) && trim((string)($guildSettings['guest_dm_message'] ?? '')) !== '') {
                    $guestDmAttempted = 1;
                    $context = [
                        'discord_display_name' => (string)($summaryMember['nickname'] !== '' ? $summaryMember['nickname'] : $summaryMember['display_name']),
                        'discord_username' => (string)$summaryMember['username'],
                        'rsn' => (string)($resolvedMember['rsn'] ?? ''),
                        'guild_name' => $guildName,
                        'guest_role' => 'Guest',
                        'clan_member_role' => 'Clan Member',
                    ];
                    $message = render_guest_dm_template((string)$guildSettings['guest_dm_message'], $context);
                    try {
                        discord_send_dm($userId, $message);
                        $guestDmSuccess = 1;
                        $counts['guest_dm_sent']++;
                    } catch (Throwable $dmError) {
                        $guestDmError = $dmError->getMessage();
                        $counts['guest_dm_failed']++;
                    }
                }
            }
        } catch (Throwable $e) {
            $memberStatus = 'error';
            $guestDmError = $e->getMessage();
            $counts['errors']++;
        }

        $memberLogStmt->execute([
            'sync_run_id' => $syncRunId,
            'discord_user_id' => $userId,
            'discord_username' => (string)$summaryMember['username'],
            'discord_display_name' => (string)($summaryMember['nickname'] !== '' ? $summaryMember['nickname'] : $summaryMember['display_name']),
            'resolved_rsn' => $resolvedMember ? (string)($resolvedMember['rsn'] ?? '') : null,
            'resolved_rank_name' => $rankName !== '' ? $rankName : null,
            'resolved_by' => $resolvedBy,
            'status' => $memberStatus,
            'added_role_ids_csv' => $addRoleIds !== [] ? implode(',', $addRoleIds) : null,
            'removed_role_ids_csv' => $removeRoleIds !== [] ? implode(',', $removeRoleIds) : null,
            'blocked_role_ids_csv' => $blockedRoleIds !== [] ? implode(',', $blockedRoleIds) : null,
            'guest_dm_attempted' => $guestDmAttempted,
            'guest_dm_success' => $guestDmSuccess,
            'guest_dm_error' => $guestDmError,
            'notes' => $issues !== [] ? implode(' | ', $issues) : null,
        ]);
    }

    $summaryText = sprintf(
        'Manual sync completed. Changed: %d | Skipped: %d | Blocked: %d | Errors: %d | Guest DMs sent: %d | Guest DMs failed: %d',
        $counts['changed'],
        $counts['skipped'],
        $counts['blocked'],
        $counts['errors'],
        $counts['guest_dm_sent'],
        $counts['guest_dm_failed']
    );

    $finishStmt = $pdo->prepare('UPDATE sync_runs
        SET status = :status,
            finished_at_utc = UTC_TIMESTAMP(3),
            total_members = :total_members,
            changed_members = :changed_members,
            skipped_members = :skipped_members,
            blocked_members = :blocked_members,
            error_members = :error_members,
            summary_text = :summary_text,
            updated_at = UTC_TIMESTAMP(3)
        WHERE id = :id');
    $finishStmt->execute([
        'status' => $counts['errors'] > 0 ? 'completed_with_errors' : 'completed',
        'total_members' => $counts['total'],
        'changed_members' => $counts['changed'],
        'skipped_members' => $counts['skipped'],
        'blocked_members' => $counts['blocked'],
        'error_members' => $counts['errors'],
        'summary_text' => $summaryText,
        'id' => $syncRunId,
    ]);

    $logChannelId = trim((string)($guildSettings['log_channel_id'] ?? ''));
    if ($logChannelId !== '') {
        try {
            discord_send_channel_message($logChannelId, 'Sync summary: ' . $summaryText);
        } catch (Throwable $ignored) {
        }
    }

    return $summaryText;
}


$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = ['all', 'ready_change', 'ready_no_change', 'blocked_hierarchy', 'ambiguous_match', 'no_match', 'no_rank_mapping'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}
$onlyChanged = isset($_GET['only_changed']) && $_GET['only_changed'] === '1';

$rows = [];
$summary = [
    'all' => 0,
    'ready_change' => 0,
    'ready_no_change' => 0,
    'blocked_hierarchy' => 0,
    'no_match' => 0,
    'ambiguous_match' => 0,
    'no_rank_mapping' => 0,
];
$errorMessage = null;
$botHighestRole = null;
$generatedAtUtc = now_utc();


if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run_sync_now') {
    verify_csrf_or_fail();

    try {
        $summaryMessage = execute_manual_sync($pdo, $guildId, $clanId);
        flash('success', $summaryMessage);
    } catch (Throwable $e) {
        flash('error', 'Manual sync failed: ' . $e->getMessage());
    }

    redirect('/admin/sync-preview.php');
}


if (!$missingTables) {
    try {
        $guildRoles = discord_get_guild_roles($guildId);
        $roleMap = discord_role_map($guildRoles);
        $rolePositions = discord_role_positions($guildRoles);
        $botUser = discord_get_bot_user();
        $botMember = discord_get_guild_member($guildId, (string)$botUser['id']);
        if ($botMember === null) {
            throw new RuntimeException('The bot user is not present in the configured guild.');
        }
        $botHighestRole = discord_member_highest_role($botMember, $rolePositions);
        if ($botHighestRole === null) {
            throw new RuntimeException('The bot has no guild role assigned.');
        }
        $botRoleIds = array_map('strval', $botMember['roles'] ?? []);

        $roleFlags = [];
        $flagStmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
        $flagStmt->execute(['guild_id' => $guildId]);
        foreach ($flagStmt->fetchAll() as $row) {
            $roleFlags[(string)$row['discord_role_id']] = $row;
        }

        $rankMappings = [];
        $mapStmt = $pdo->prepare('SELECT rs_rank_name, discord_role_id, is_enabled FROM rs_rank_mappings WHERE clan_id = :clan_id ORDER BY rs_rank_name ASC, id ASC');
        $mapStmt->execute(['clan_id' => $clanId]);
        foreach ($mapStmt->fetchAll() as $row) {
            $rankName = (string)$row['rs_rank_name'];
            if (!isset($rankMappings[$rankName])) {
                $rankMappings[$rankName] = ['is_enabled' => (int)$row['is_enabled'] === 1, 'role_ids' => []];
            }
            $rankMappings[$rankName]['is_enabled'] = (int)$row['is_enabled'] === 1;
            $roleId = trim((string)($row['discord_role_id'] ?? ''));
            if ($roleId !== '') {
                $rankMappings[$rankName]['role_ids'][] = $roleId;
            }
        }

        $manualMappings = [];
        $manualStmt = $pdo->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id');
        $manualStmt->execute(['clan_id' => $clanId, 'guild_id' => $guildId]);
        foreach ($manualStmt->fetchAll() as $row) {
            $manualMappings[(string)$row['discord_user_id']] = $row;
        }

        $clanMembers = [];
        $clanById = [];
        $clanByNormalised = [];
        $clanStmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id AND is_active = 1 ORDER BY rsn ASC');
        $clanStmt->execute(['clan_id' => $clanId]);
        foreach (($clanStmt->fetchAll() ?: []) as $member) {
            $clanMembers[] = $member;
            $clanById[(string)$member['id']] = $member;
            $norm = (string)$member['rsn_normalised'];
            if ($norm !== '' && !isset($clanByNormalised[$norm])) {
                $clanByNormalised[$norm] = $member;
            }
        }

        $discordMembers = discord_list_guild_members($guildId);
        foreach ($discordMembers as $discordMember) {
            if ((bool)($discordMember['user']['bot'] ?? false)) {
                continue;
            }

            $summaryMember = discord_format_member_summary($discordMember);
            $userId = (string)$summaryMember['user_id'];
            $manual = $manualMappings[$userId] ?? null;

            $resolvedMember = null;
            $resolvedBy = 'none';
            if ($manual && isset($clanById[(string)$manual['member_id']])) {
                $resolvedMember = $clanById[(string)$manual['member_id']];
                $resolvedBy = 'manual';
            } else {
                $nickname = (string)$summaryMember['nickname'];
                $displayName = (string)$summaryMember['display_name'];
                $username = (string)$summaryMember['username'];
                $fallbackMatch = resolve_clan_member_fallback($clanByNormalised, [$nickname, $displayName, $username]);
                $resolvedMember = $fallbackMatch['member'] ?? null;
                $resolvedBy = $resolvedMember ? ('nickname_' . (string)($fallbackMatch['match_type'] ?? 'exact')) : (!empty($fallbackMatch['ambiguous']) ? 'ambiguous' : 'none');
            }

            $currentRoleIds = array_values(array_filter(array_map('strval', $discordMember['roles'] ?? []), static fn(string $id): bool => $id !== ''));
            $currentRoles = preview_member_roles($currentRoleIds, $roleMap, $roleFlags);

            $rankName = $resolvedMember ? (string)($resolvedMember['rank_name'] ?? '') : '';
            $resolvedIsGuest = !$resolvedMember;
            $targetRoleIds = [];
            $targetReasons = [];

            $baseRowName = $resolvedIsGuest || strcasecmp($rankName, 'Guest') === 0 ? 'Guest' : 'Clan Member';
            $baseMappings = $rankMappings[$baseRowName]['role_ids'] ?? [];
            $baseEnabled = !empty($rankMappings[$baseRowName]['is_enabled']);
            if ($baseEnabled) {
                foreach ($baseMappings as $roleId) {
                    $targetRoleIds[] = (string)$roleId;
                    $targetReasons[(string)$roleId] = $baseRowName;
                }
            }

            if ($resolvedMember) {
                $exactMappings = $rankMappings[$rankName]['role_ids'] ?? [];
                $exactEnabled = !empty($rankMappings[$rankName]['is_enabled']);
                if ($exactEnabled) {
                    foreach ($exactMappings as $roleId) {
                        $targetRoleIds[] = (string)$roleId;
                        $targetReasons[(string)$roleId] = $rankName;
                    }
                }
            }
            $targetRoleIds = array_values(array_unique(array_filter($targetRoleIds)));

            $addRoleIds = [];
            $removeRoleIds = [];
            $keepRoleIds = [];
            $blockedRoleIds = [];
            $statusKey = 'ready_no_change';
            $issues = [];

            if ($resolvedBy === 'ambiguous') {
                $statusKey = 'ambiguous_match';
                $issues[] = 'Multiple RuneScape clan members partially matched this Discord name. Save a manual mapping to resolve it.';
            } elseif ($targetRoleIds === []) {
                $statusKey = 'no_rank_mapping';
                $issues[] = $resolvedMember
                    ? 'The resolved RuneScape rank does not currently produce any target Discord roles.'
                    : 'No RuneScape match was resolved, and the Guest mapping does not currently produce any target Discord roles.';
            } else {
                foreach ($targetRoleIds as $roleId) {
                    if (!in_array($roleId, $currentRoleIds, true)) {
                        $addRoleIds[] = $roleId;
                    } else {
                        $keepRoleIds[] = $roleId;
                    }
                    $role = $roleMap[$roleId] ?? null;
                    if ($role && (int)($role['position'] ?? -1) >= (int)$botHighestRole['position']) {
                        $blockedRoleIds[] = $roleId;
                    }
                }

                $managedRemovableRoleIds = [];
                foreach ($currentRoleIds as $roleId) {
                    if (in_array($roleId, $botRoleIds, true)) {
                        continue;
                    }
                    $flag = $roleFlags[$roleId] ?? null;
                    $isProtected = !empty($flag['is_protected_role']);
                    $isBotFlag = !empty($flag['is_bot_role']);
                    $isMappedRole = false;
                    foreach ($rankMappings as $mappingRow) {
                        if (in_array($roleId, $mappingRow['role_ids'] ?? [], true)) {
                            $isMappedRole = true;
                            break;
                        }
                    }
                    if (($isBotFlag || $isMappedRole) && !$isProtected && !in_array($roleId, $targetRoleIds, true)) {
                        $managedRemovableRoleIds[] = $roleId;
                    }
                }

                foreach ($managedRemovableRoleIds as $roleId) {
                    $role = $roleMap[$roleId] ?? null;
                    if ($role && (int)($role['position'] ?? -1) >= (int)$botHighestRole['position']) {
                        $blockedRoleIds[] = $roleId;
                    }
                    $removeRoleIds[] = $roleId;
                }

                if (!$resolvedMember) {
                    $issues[] = 'No RuneScape clan member match was resolved. Preview falls back to Guest and removes non-protected managed roles.';
                }

                $blockedRoleIds = array_values(array_unique($blockedRoleIds));
                if ($blockedRoleIds !== []) {
                    $statusKey = 'blocked_hierarchy';
                    foreach ($blockedRoleIds as $blockedRoleId) {
                        if (isset($roleMap[$blockedRoleId])) {
                            $issues[] = 'Bot hierarchy does not allow managing role "' . (string)$roleMap[$blockedRoleId]['name'] . '".';
                        }
                    }
                } elseif ($addRoleIds !== [] || $removeRoleIds !== []) {
                    $statusKey = 'ready_change';
                }
            }

            $summary['all']++;
            $summary[$statusKey]++;

            $row = [
                'user_id' => $userId,
                'avatar_url' => (string)$summaryMember['avatar_url'],
                'display_name' => (string)$summaryMember['display_name'],
                'username' => (string)$summaryMember['username'],
                'nickname' => (string)$summaryMember['nickname'],
                'current_roles' => $currentRoles,
                'resolved_member' => $resolvedMember,
                'resolved_by' => $resolvedBy,
                'rank_name' => $rankName,
                'status_key' => $statusKey,
                'target_roles' => preview_member_roles($targetRoleIds, $roleMap, $roleFlags),
                'add_roles' => preview_member_roles($addRoleIds, $roleMap, $roleFlags),
                'remove_roles' => preview_member_roles($removeRoleIds, $roleMap, $roleFlags),
                'keep_roles' => preview_member_roles($keepRoleIds, $roleMap, $roleFlags),
                'issues' => $issues,
            ];

            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['display_name'],
                $row['username'],
                $row['nickname'],
                (string)($resolvedMember['rsn'] ?? ''),
                $rankName,
                $userId,
                implode(' ', array_map(static fn(array $role): string => (string)$role['name'], $currentRoles)),
            ])), 'UTF-8');

            if ($search !== '' && !str_contains($haystack, mb_strtolower($search, 'UTF-8'))) {
                continue;
            }
            if ($statusFilter !== 'all' && $statusKey !== $statusFilter) {
                continue;
            }
            if ($onlyChanged && $statusKey !== 'ready_change' && $statusKey !== 'blocked_hierarchy') {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $priority = [
                'blocked_hierarchy' => 0,
                'ready_change' => 1,
                'no_rank_mapping' => 2,
                'ambiguous_match' => 3,
                'no_match' => 4,
                'ready_no_change' => 5,
            ];
            $ap = $priority[$a['status_key']] ?? 99;
            $bp = $priority[$b['status_key']] ?? 99;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
        });
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <div class="table-actions" style="margin-top:0;">
        <div>
            <h2 style="margin:0 0 8px 0;">Sync Preview</h2>
            <p class="muted" style="margin:0;">Review the proposed changes below, then use <strong>Run Sync Now</strong> to apply the same logic live. It resolves each Discord user to a RuneScape member using manual mappings first and nickname fallback second.</p>
        </div>
        <?php if (!$missingTables && !$errorMessage): ?>
        <form method="post" onsubmit="return confirm('Run live sync now? This will apply Discord role changes.');">
            <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
            <input type="hidden" name="action" value="run_sync_now">
            <button class="btn-primary" type="submit">Run Sync Now</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($missingTables): ?>
    <div class="card">
        <span class="status bad">Setup Required</span>
        <p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p>
    </div>
<?php elseif ($errorMessage): ?>
    <div class="card">
        <span class="status bad">Error</span>
        <p><?= h($errorMessage) ?></p>
    </div>
<?php else: ?>
    <div class="preview-grid">
        <div class="stat"><div class="muted">Users evaluated</div><div class="value"><?= h((string)$summary['all']) ?></div></div>
        <div class="stat"><div class="muted">Changes ready</div><div class="value"><?= h((string)$summary['ready_change']) ?></div></div>
        <div class="stat"><div class="muted">Blocked by hierarchy</div><div class="value"><?= h((string)$summary['blocked_hierarchy']) ?></div></div>
        <div class="stat"><div class="muted">Ambiguous match</div><div class="value"><?= h((string)$summary['ambiguous_match']) ?></div></div>
        <div class="stat"><div class="muted">No RS match</div><div class="value"><?= h((string)$summary['no_match']) ?></div></div>
        <div class="stat"><div class="muted">No rank mapping</div><div class="value"><?= h((string)$summary['no_rank_mapping']) ?></div></div>
    </div>

    <form method="get" class="card">
        <div class="toolbar">
            <div class="grow">
                <label class="small muted" for="search">Search</label>
                <input type="text" id="search" name="search" value="<?= h($search) ?>" placeholder="Discord name, nickname, RSN, role, or user ID">
            </div>
            <div>
                <label class="small muted" for="status">Status</label>
                <select id="status" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="ready_change" <?= $statusFilter === 'ready_change' ? 'selected' : '' ?>>Ready: Change</option>
                    <option value="ready_no_change" <?= $statusFilter === 'ready_no_change' ? 'selected' : '' ?>>Ready: No Change</option>
                    <option value="blocked_hierarchy" <?= $statusFilter === 'blocked_hierarchy' ? 'selected' : '' ?>>Blocked: Hierarchy</option>
                    <option value="ambiguous_match" <?= $statusFilter === 'ambiguous_match' ? 'selected' : '' ?>>Ambiguous Match</option>
                    <option value="no_match" <?= $statusFilter === 'no_match' ? 'selected' : '' ?>>No Match</option>
                    <option value="no_rank_mapping" <?= $statusFilter === 'no_rank_mapping' ? 'selected' : '' ?>>No Rank Mapping</option>
                </select>
            </div>
            <div>
                <label class="small muted" for="only_changed">Changed only</label>
                <select id="only_changed" name="only_changed">
                    <option value="0" <?= !$onlyChanged ? 'selected' : '' ?>>Show all</option>
                    <option value="1" <?= $onlyChanged ? 'selected' : '' ?>>Changes only</option>
                </select>
            </div>
            <div>
                <button class="btn-primary" type="submit">Apply filters</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-actions">
            <div class="hint">Generated at <?= h($generatedAtUtc) ?> UTC. Bot highest role: <strong><?= h((string)($roleMap[$botHighestRole['id']]['name'] ?? 'Unknown')) ?></strong></div>
            <div class="hint"><?= h((string)count($rows)) ?> row(s) shown</div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Resolved RS Member</th>
                    <th>Current Roles</th>
                    <th>Target Roles</th>
                    <th>Preview</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): [$statusText, $statusClass] = sync_status_label($row['status_key']); ?>
                <tr>
                    <td>
                        <div class="stack">
                            <div class="inline">
                                <img class="avatar" src="<?= h($row['avatar_url']) ?>" alt="">
                                <div class="stack">
                                    <strong><?= h($row['display_name']) ?></strong>
                                    <span class="muted small">@<?= h($row['username']) ?></span>
                                </div>
                            </div>
                            <?php if ($row['nickname'] !== ''): ?><div class="small muted">Nickname: <?= h($row['nickname']) ?></div><?php endif; ?>
                            <div class="small muted mono"><?= h($row['user_id']) ?></div>
                        </div>
                    </td>
                    <td>
                        <?php if ($row['resolved_member']): ?>
                            <div class="stack">
                                <strong><?= h((string)$row['resolved_member']['rsn']) ?></strong>
                                <div class="small muted">Rank: <?= h($row['rank_name'] !== '' ? $row['rank_name'] : 'Unknown') ?></div>
                                <div><span class="code-badge"><?php if ($row['resolved_by'] === 'manual'): ?>Manual mapping<?php elseif ($row['resolved_by'] === 'nickname_exact'): ?>Nickname exact<?php elseif ($row['resolved_by'] === 'nickname_exact_compact'): ?>Nickname exact (space-insensitive)<?php elseif ($row['resolved_by'] === 'nickname_contains'): ?>Nickname contains RSN<?php elseif ($row['resolved_by'] === 'none'): ?>Guest fallback<?php else: ?>Nickname fallback<?php endif; ?></span></div>
                            </div>
                        <?php else: ?>
                            <div class="stack">
                                <strong>Guest</strong>
                                <div class="small muted">No RuneScape match resolved</div>
                                <div><span class="code-badge">Guest fallback</span></div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= role_chip_html($row['current_roles']) ?></td>
                    <td><?= role_chip_html($row['target_roles']) ?></td>
                    <td>
                        <div class="stack">
                            <div><span class="small muted">Add</span><?= role_chip_html($row['add_roles'], ['add']) ?></div>
                            <div><span class="small muted">Remove</span><?= role_chip_html($row['remove_roles'], ['remove']) ?></div>
                        </div>
                    </td>
                    <td>
                        <div class="stack">
                            <span class="status <?= h($statusClass) ?>"><?= h($statusText) ?></span>
                            <?php if ($row['issues']): ?>
                                <ul class="small muted" style="margin:0; padding-left:18px;">
                                    <?php foreach ($row['issues'] as $issue): ?>
                                        <li><?= h($issue) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="small muted">No issues detected.</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="muted">No rows match the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
