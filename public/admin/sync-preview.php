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

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = ['all', 'ready_change', 'ready_no_change', 'blocked_hierarchy', 'no_match', 'no_rank_mapping'];
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
    'no_rank_mapping' => 0,
];
$errorMessage = null;
$botHighestRole = null;
$generatedAtUtc = now_utc();

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

                foreach ([$nickname, $displayName, $username] as $candidate) {
                    $candidateNorm = normalise_match_source((string)$candidate);
                    if ($candidateNorm !== '' && isset($clanByNormalised[$candidateNorm])) {
                        $resolvedMember = $clanByNormalised[$candidateNorm];
                        $resolvedBy = 'nickname';
                        break;
                    }
                }
            }

            $currentRoleIds = array_values(array_filter(array_map('strval', $discordMember['roles'] ?? []), static fn(string $id): bool => $id !== ''));
            $currentRoles = preview_member_roles($currentRoleIds, $roleMap, $roleFlags);

            $rankName = $resolvedMember ? (string)($resolvedMember['rank_name'] ?? '') : '';
            $targetRoleIds = [];
            $targetReasons = [];
            if ($resolvedMember) {
                $baseRowName = (strcasecmp($rankName, 'Guest') === 0) ? 'Guest' : 'Clan Member';
                $exactMappings = $rankMappings[$rankName]['role_ids'] ?? [];
                $baseMappings = $rankMappings[$baseRowName]['role_ids'] ?? [];
                $exactEnabled = !empty($rankMappings[$rankName]['is_enabled']);
                $baseEnabled = !empty($rankMappings[$baseRowName]['is_enabled']);

                if ($baseEnabled) {
                    foreach ($baseMappings as $roleId) {
                        $targetRoleIds[] = (string)$roleId;
                        $targetReasons[(string)$roleId] = $baseRowName;
                    }
                }
                if ($exactEnabled) {
                    foreach ($exactMappings as $roleId) {
                        $targetRoleIds[] = (string)$roleId;
                        $targetReasons[(string)$roleId] = $rankName;
                    }
                }
                $targetRoleIds = array_values(array_unique(array_filter($targetRoleIds)));
            }

            $addRoleIds = [];
            $removeRoleIds = [];
            $keepRoleIds = [];
            $blockedRoleIds = [];
            $statusKey = 'ready_no_change';
            $issues = [];

            if (!$resolvedMember) {
                $statusKey = 'no_match';
                $issues[] = 'No RuneScape clan member match could be resolved from a manual mapping or nickname fallback.';
            } elseif ($targetRoleIds === []) {
                $statusKey = 'no_rank_mapping';
                $issues[] = 'The resolved RuneScape rank does not currently produce any target Discord roles.';
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
                'no_match' => 3,
                'ready_no_change' => 4,
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
    <h2>Sync Preview</h2>
    <p class="muted">This is a dry run only. No Discord roles are changed from this page. It resolves each Discord user to a RuneScape member using manual mappings first and nickname fallback second, then shows the role changes that would be made.</p>
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
                                <div><span class="code-badge"><?= h($row['resolved_by'] === 'manual' ? 'Manual mapping' : 'Nickname fallback') ?></span></div>
                            </div>
                        <?php else: ?>
                            <span class="muted">No match</span>
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
