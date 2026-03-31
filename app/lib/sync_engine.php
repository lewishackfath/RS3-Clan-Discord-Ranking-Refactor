<?php
declare(strict_types=1);

function sync_build_role_name_list(array $roleIds, array $roleMap): string
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

function sync_maybe_log_member_change_embed(array $guildSettings, array $summaryMember, ?array $resolvedMember, string $rankName, array $addRoleIds, array $removeRoleIds, array $currentRoleIds, array $roleMap): void
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
                'value' => sync_build_role_name_list($addRoleIds, $roleMap),
                'inline' => false,
            ],
            [
                'name' => 'Roles Removed',
                'value' => sync_build_role_name_list($removeRoleIds, $roleMap),
                'inline' => false,
            ],
            [
                'name' => 'Current Roles',
                'value' => sync_build_role_name_list($currentRoleIds, $roleMap),
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

function sync_render_guest_dm_template(string $template, array $context): string
{
    $replace = [];
    foreach ($context as $key => $value) {
        $replace['{' . $key . '}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function sync_update_run_status(PDO $pdo, int $syncRunId, array $counts, string $status, string $summaryText): void
{
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
        'status' => $status,
        'total_members' => (int)($counts['total'] ?? 0),
        'changed_members' => (int)($counts['changed'] ?? 0),
        'skipped_members' => (int)($counts['skipped'] ?? 0),
        'blocked_members' => (int)($counts['blocked'] ?? 0),
        'error_members' => (int)($counts['errors'] ?? 0),
        'summary_text' => $summaryText,
        'id' => $syncRunId,
    ]);
}

function execute_sync_run(PDO $pdo, string $guildId, int $clanId, array $options = []): string
{
    $missingApplyTables = require_tables($pdo, ['sync_runs', 'sync_run_members']);
    if ($missingApplyTables) {
        throw new RuntimeException('Sync audit tables are missing. Run sql/migrations/phase3.0-manual-apply-sync-audit-log.sql first.');
    }

    $triggerSource = strtolower(trim((string)($options['trigger_source'] ?? 'manual')));
    if (!in_array($triggerSource, ['manual', 'auto'], true)) {
        $triggerSource = 'manual';
    }

    $admin = current_admin();
    $initiatedByDiscordUserId = array_key_exists('initiated_by_discord_user_id', $options)
        ? (trim((string)$options['initiated_by_discord_user_id']) !== '' ? trim((string)$options['initiated_by_discord_user_id']) : null)
        : ((string)($admin['id'] ?? '') !== '' ? (string)$admin['id'] : null);
    $initiatedByName = array_key_exists('initiated_by_name', $options)
        ? trim((string)$options['initiated_by_name'])
        : trim((string)($admin['username'] ?? 'Admin'));
    if ($initiatedByName === '') {
        $initiatedByName = $triggerSource === 'auto' ? 'Automatic Scheduler' : 'Admin';
    }

    $hasTriggerSourceColumn = column_exists($pdo, 'sync_runs', 'trigger_source');
    if ($hasTriggerSourceColumn) {
        $runStmt = $pdo->prepare('INSERT INTO sync_runs (
            clan_id, discord_guild_id, initiated_by_discord_user_id, initiated_by_name, trigger_source, status, started_at_utc, created_at, updated_at
        ) VALUES (
            :clan_id, :discord_guild_id, :initiated_by_discord_user_id, :initiated_by_name, :trigger_source, :status, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
        )');
        $runStmt->execute([
            'clan_id' => $clanId,
            'discord_guild_id' => $guildId,
            'initiated_by_discord_user_id' => $initiatedByDiscordUserId,
            'initiated_by_name' => $initiatedByName,
            'trigger_source' => $triggerSource,
            'status' => 'running',
        ]);
    } else {
        $runStmt = $pdo->prepare('INSERT INTO sync_runs (
            clan_id, discord_guild_id, initiated_by_discord_user_id, initiated_by_name, status, started_at_utc, created_at, updated_at
        ) VALUES (
            :clan_id, :discord_guild_id, :initiated_by_discord_user_id, :initiated_by_name, :status, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
        )');
        $runStmt->execute([
            'clan_id' => $clanId,
            'discord_guild_id' => $guildId,
            'initiated_by_discord_user_id' => $initiatedByDiscordUserId,
            'initiated_by_name' => $initiatedByName,
            'status' => 'running',
        ]);
    }

    $syncRunId = (int)$pdo->lastInsertId();
    $counts = [
        'total' => 0,
        'changed' => 0,
        'skipped' => 0,
        'blocked' => 0,
        'errors' => 0,
        'guest_dm_sent' => 0,
        'guest_dm_failed' => 0,
    ];

    try {
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

        $memberLogStmt = $pdo->prepare('INSERT INTO sync_run_members (
            sync_run_id, discord_user_id, discord_username, discord_display_name, resolved_rsn, resolved_rank_name, resolved_by,
            status, added_role_ids_csv, removed_role_ids_csv, blocked_role_ids_csv, guest_dm_attempted, guest_dm_success,
            guest_dm_error, notes, created_at, updated_at
        ) VALUES (
            :sync_run_id, :discord_user_id, :discord_username, :discord_display_name, :resolved_rsn, :resolved_rank_name, :resolved_by,
            :status, :added_role_ids_csv, :removed_role_ids_csv, :blocked_role_ids_csv, :guest_dm_attempted, :guest_dm_success,
            :guest_dm_error, :notes, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
        )');

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
                            sync_maybe_log_member_change_embed($guildSettings, $summaryMember, $resolvedMember, $rankName, $addRoleIds, $removeRoleIds, $newRoleIds, $roleMap);
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
                        $message = sync_render_guest_dm_template((string)$guildSettings['guest_dm_message'], $context);
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
            '%s sync completed. Changed: %d | Skipped: %d | Blocked: %d | Errors: %d | Guest DMs sent: %d | Guest DMs failed: %d',
            ucfirst($triggerSource),
            $counts['changed'],
            $counts['skipped'],
            $counts['blocked'],
            $counts['errors'],
            $counts['guest_dm_sent'],
            $counts['guest_dm_failed']
        );

        sync_update_run_status(
            $pdo,
            $syncRunId,
            $counts,
            $counts['errors'] > 0 ? 'completed_with_errors' : 'completed',
            $summaryText
        );

        $logChannelId = trim((string)($guildSettings['log_channel_id'] ?? ''));
        if ($logChannelId !== '') {
            try {
                discord_send_channel_message($logChannelId, 'Sync summary: ' . $summaryText);
            } catch (Throwable $ignored) {
            }
        }

        return $summaryText;
    } catch (Throwable $e) {
        $summaryText = ucfirst($triggerSource) . ' sync failed: ' . $e->getMessage();
        sync_update_run_status($pdo, $syncRunId, $counts, 'failed', $summaryText);
        throw $e;
    }
}


function auto_sync_update_guild_status(PDO $pdo, int $clanId, array $fields): void
{
    if ($clanId <= 0 || $fields === []) {
        return;
    }

    $assignments = [];
    $params = ['clan_id' => $clanId];
    foreach ($fields as $column => $value) {
        $assignments[] = $column . ' = :' . $column;
        $params[$column] = $value;
    }
    $assignments[] = 'updated_at = CURRENT_TIMESTAMP';

    $sql = 'UPDATE guild_settings SET ' . implode(', ', $assignments) . ' WHERE clan_id = :clan_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function perform_auto_sync_for_clan(PDO $pdo, int $clanId, string $guildId, array $options = []): array
{
    if ($clanId <= 0) {
        throw new RuntimeException('A valid clan ID is required for automatic sync.');
    }
    $guildId = trim($guildId);
    if ($guildId === '') {
        throw new RuntimeException('A valid Discord guild ID is required for automatic sync.');
    }

    $clanName = trim((string)($options['clan_name'] ?? env('CLAN_NAME', '')));
    if ($clanName === '') {
        auto_sync_update_guild_status($pdo, $clanId, [
            'last_roster_import_at' => now_utc(),
            'last_roster_import_status' => 'error',
            'last_roster_import_message' => 'CLAN_NAME is missing from .env, so the latest RuneScape roster could not be imported.',
            'last_auto_sync_status' => 'error',
            'last_auto_sync_message' => 'Automatic sync skipped because CLAN_NAME is missing from .env.',
        ]);
        throw new RuntimeException('CLAN_NAME is missing from .env.');
    }

    try {
        $import = import_runescape_clan_members($pdo, $clanId, $clanName);
        $importMessage = sprintf(
            'Imported %d members from RuneScape for %s. Inserted %d, updated %d, reactivated %d, marked inactive %d.',
            (int)($import['fetched'] ?? 0),
            (string)($import['clan_name'] ?? $clanName),
            (int)($import['inserted'] ?? 0),
            (int)($import['updated'] ?? 0),
            (int)($import['reactivated'] ?? 0),
            (int)($import['marked_inactive'] ?? 0)
        );

        auto_sync_update_guild_status($pdo, $clanId, [
            'last_roster_import_at' => now_utc(),
            'last_roster_import_status' => 'ok',
            'last_roster_import_message' => $importMessage,
            'last_auto_sync_status' => 'running',
            'last_auto_sync_message' => 'Roster import succeeded. Live sync is now running.',
        ]);
    } catch (Throwable $e) {
        auto_sync_update_guild_status($pdo, $clanId, [
            'last_roster_import_at' => now_utc(),
            'last_roster_import_status' => 'error',
            'last_roster_import_message' => $e->getMessage(),
            'last_auto_sync_status' => 'error',
            'last_auto_sync_message' => 'Automatic sync skipped because the latest RuneScape roster import failed.',
        ]);
        throw $e;
    }

    try {
        $summary = execute_sync_run($pdo, $guildId, $clanId, [
            'trigger_source' => (string)($options['trigger_source'] ?? 'auto'),
            'initiated_by_discord_user_id' => $options['initiated_by_discord_user_id'] ?? null,
            'initiated_by_name' => $options['initiated_by_name'] ?? 'Automatic Scheduler',
        ]);

        auto_sync_update_guild_status($pdo, $clanId, [
            'last_auto_sync_at' => now_utc(),
            'last_auto_sync_status' => 'ok',
            'last_auto_sync_message' => $summary,
        ]);

        return [
            'import' => $import,
            'summary' => $summary,
        ];
    } catch (Throwable $e) {
        auto_sync_update_guild_status($pdo, $clanId, [
            'last_auto_sync_status' => 'error',
            'last_auto_sync_message' => $e->getMessage(),
        ]);
        throw $e;
    }
}

function sync_acquire_process_lock(string $lockFile)
{
    $directory = dirname($lockFile);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $handle = fopen($lockFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open sync lock file: ' . $lockFile);
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }

    ftruncate($handle, 0);
    fwrite($handle, (string)getmypid());
    fflush($handle);

    return $handle;
}

function sync_release_process_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    flock($handle, LOCK_UN);
    fclose($handle);
}
