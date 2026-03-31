<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['discord_user_mappings', 'discord_role_flags', 'clan_members']);
$guildRoles = [];
$guildRoleMap = [];

function member_has_bot_role(array $roleIds, array $roleFlags): bool
{
    foreach ($roleIds as $roleId) {
        $roleId = (string)$roleId;
        if ($roleId === '') {
            continue;
        }
        $flag = $roleFlags[$roleId] ?? null;
        if (!empty($flag['is_bot_role'])) {
            return true;
        }
    }
    return false;
}

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();

    try {
        $discordMembers = discord_list_guild_members($guildId);

        $roleFlags = [];
        $flagStmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
        $flagStmt->execute(['guild_id' => $guildId]);
        foreach (($flagStmt->fetchAll() ?: []) as $row) {
            $roleFlags[(string)$row['discord_role_id']] = $row;
        }

        $discordMembersById = [];
        foreach ($discordMembers as $discordMember) {
            $summary = discord_format_member_summary($discordMember);
            $discordMembersById[(string)$summary['user_id']] = [
                'member' => $discordMember,
                'summary' => $summary,
            ];
        }

        $memberChoices = is_array($_POST['member_id'] ?? null) ? $_POST['member_id'] : [];

        $clanMembersById = [];
        $clanStmt = $pdo->prepare('SELECT id, rsn, rank_name FROM clan_members WHERE clan_id = :clan_id AND is_active = 1');
        $clanStmt->execute(['clan_id' => $clanId]);
        foreach (($clanStmt->fetchAll() ?: []) as $member) {
            $clanMembersById[(string)$member['id']] = $member;
        }

        $upsert = $pdo->prepare('INSERT INTO discord_user_mappings (clan_id, discord_guild_id, discord_user_id, member_id, rsn_cache, discord_username_cache, discord_nickname_cache) VALUES (:clan_id, :guild_id, :discord_user_id, :member_id, :rsn_cache, :discord_username_cache, :discord_nickname_cache) ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), rsn_cache = VALUES(rsn_cache), discord_username_cache = VALUES(discord_username_cache), discord_nickname_cache = VALUES(discord_nickname_cache)');
        $delete = $pdo->prepare('DELETE FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id AND discord_user_id = :discord_user_id');

        $saved = 0;
        $cleared = 0;
        $skippedBotRole = 0;
        foreach ($memberChoices as $userId => $selectedMemberId) {
            $userId = trim((string)$userId);
            if ($userId === '' || !isset($discordMembersById[$userId])) {
                continue;
            }

            $discordMember = $discordMembersById[$userId]['member'];
            $summary = $discordMembersById[$userId]['summary'];
            if ((bool)($discordMember['user']['bot'] ?? false)) {
                continue;
            }

            $currentRoleIds = array_values(array_filter(array_map('strval', $discordMember['roles'] ?? []), static fn(string $id): bool => $id !== ''));
            if (member_has_bot_role($currentRoleIds, $roleFlags)) {
                $skippedBotRole++;
                continue;
            }

            $memberId = trim((string)$selectedMemberId);

            if ($memberId === '') {
                $delete->execute([
                    'clan_id' => $clanId,
                    'guild_id' => $guildId,
                    'discord_user_id' => $userId,
                ]);
                $cleared += $delete->rowCount() > 0 ? 1 : 0;
                continue;
            }

            if (!isset($clanMembersById[$memberId])) {
                continue;
            }

            $member = $clanMembersById[$memberId];
            $upsert->execute([
                'clan_id' => $clanId,
                'guild_id' => $guildId,
                'discord_user_id' => $userId,
                'member_id' => (int)$member['id'],
                'rsn_cache' => (string)$member['rsn'],
                'discord_username_cache' => (string)$summary['username'],
                'discord_nickname_cache' => (string)$summary['nickname'],
            ]);
            $saved++;
        }

        $message = 'User mappings saved. Manual mappings updated: ' . $saved . '. Cleared mappings: ' . $cleared . '.';
        if ($skippedBotRole > 0) {
            $message .= ' Skipped bot-role users: ' . $skippedBotRole . '.';
        }
        $message .= ' Blank selections remain runtime-only nickname fallbacks.';
        flash('success', $message);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/user-mappings.php');
}

$discordMembers = [];
$manualMappings = [];
$clanMembers = [];
$memberByNormalisedRsn = [];
$summaryCounts = ['all' => 0, 'manual' => 0, 'nickname' => 0, 'ambiguous' => 0, 'unmatched' => 0];

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = ['all', 'manual', 'nickname', 'ambiguous', 'unmatched'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

if (!$missingTables) {
    $guildRoles = discord_get_guild_roles($guildId);
    $guildRoleMap = discord_role_map($guildRoles);
    $discordMembersRaw = discord_list_guild_members($guildId);

    $roleFlags = [];
    $flagStmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
    $flagStmt->execute(['guild_id' => $guildId]);
    foreach (($flagStmt->fetchAll() ?: []) as $row) {
        $roleFlags[(string)$row['discord_role_id']] = $row;
    }

    $stmt = $pdo->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id');
    $stmt->execute(['clan_id' => $clanId, 'guild_id' => $guildId]);
    foreach ($stmt->fetchAll() as $row) {
        $manualMappings[(string)$row['discord_user_id']] = $row;
    }

    $clanStmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id AND is_active = 1 ORDER BY rsn ASC');
    $clanStmt->execute(['clan_id' => $clanId]);
    $clanMembers = $clanStmt->fetchAll() ?: [];
    foreach ($clanMembers as $member) {
        $key = (string)$member['rsn_normalised'];
        if ($key !== '' && !isset($memberByNormalisedRsn[$key])) {
            $memberByNormalisedRsn[$key] = $member;
        }
    }

    foreach ($discordMembersRaw as $member) {
        $summary = discord_format_member_summary($member);
        if ((bool)($member['user']['bot'] ?? false)) {
            continue;
        }

        $currentRoleIds = array_values(array_filter(array_map('strval', $member['roles'] ?? []), static fn(string $id): bool => $id !== ''));
        if (member_has_bot_role($currentRoleIds, $roleFlags)) {
            continue;
        }

        $userId = (string)$summary['user_id'];
        $manual = $manualMappings[$userId] ?? null;
        $nickname = (string)$summary['nickname'];
        $username = (string)$summary['username'];
        $displayName = (string)$summary['display_name'];

        $fallbackMatch = resolve_clan_member_fallback($memberByNormalisedRsn, [$nickname, $displayName, $username]);
        $nicknameMatch = $fallbackMatch['member'] ?? null;
        $nicknameMatchType = (string)($fallbackMatch['match_type'] ?? 'none');
        $isAmbiguous = !empty($fallbackMatch['ambiguous']);

        $statusKey = $manual ? 'manual' : ($nicknameMatch ? 'nickname' : ($isAmbiguous ? 'ambiguous' : 'unmatched'));
        $summaryCounts['all']++;
        $summaryCounts[$statusKey]++;

        $haystack = mb_strtolower(implode(' ', array_filter([
            $displayName,
            $username,
            $nickname,
            (string)($manual['rsn_cache'] ?? ''),
            (string)($nicknameMatch['rsn'] ?? ''),
            $userId,
        ])), 'UTF-8');

        if ($search !== '' && !str_contains($haystack, mb_strtolower($search, 'UTF-8'))) {
            continue;
        }
        if ($statusFilter !== 'all' && $statusKey !== $statusFilter) {
            continue;
        }

        $currentRoles = [];
        foreach ($currentRoleIds as $roleId) {
            $role = $guildRoleMap[(string)$roleId] ?? null;
            if (!is_array($role)) {
                continue;
            }
            $roleName = (string)($role['name'] ?? '');
            if ($roleName === '' || $roleName === '@everyone') {
                continue;
            }
            $currentRoles[] = [
                'id' => (string)$roleId,
                'name' => $roleName,
                'position' => (int)($role['position'] ?? 0),
                'managed' => (bool)($role['managed'] ?? false),
            ];
        }
        usort($currentRoles, static function(array $a, array $b): int {
            $posCompare = ($b['position'] <=> $a['position']);
            if ($posCompare !== 0) {
                return $posCompare;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        $discordMembers[] = [
            'summary' => $summary,
            'manual' => $manual,
            'nickname_match' => $nicknameMatch,
            'nickname_match_type' => $nicknameMatchType,
            'status_key' => $statusKey,
            'current_roles' => $currentRoles,
        ];
    }

    usort($discordMembers, static function(array $a, array $b): int {
        $aName = (string)($a['summary']['display_name'] ?? '');
        $bName = (string)($b['summary']['display_name'] ?? '');
        return strcasecmp($aName, $bName);
    });
}

$statusMeta = [
    'manual' => ['label' => 'Manual Mapping', 'class' => 'ok'],
    'nickname' => ['label' => 'Nickname Match Available', 'class' => 'warn'],
    'ambiguous' => ['label' => 'Ambiguous Nickname Match', 'class' => 'warn'],
    'unmatched' => ['label' => 'Unmatched', 'class' => 'bad'],
];

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>User Mappings</h2>
    <p class="muted">Only manual selections are stored here. If a user has no saved mapping, runtime logic should fall back to nickname searching. Nickname previews are shown for guidance only and are never saved unless you choose a manual mapping.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
<div class="card">
    <div class="stat-grid">
        <div class="stat"><div class="muted small">Discord members</div><div class="value"><?= h((string)$summaryCounts['all']) ?></div></div>
        <div class="stat"><div class="muted small">Manual mappings</div><div class="value"><?= h((string)$summaryCounts['manual']) ?></div></div>
        <div class="stat"><div class="muted small">Nickname matches</div><div class="value"><?= h((string)$summaryCounts['nickname']) ?></div></div>
        <div class="stat"><div class="muted small">Ambiguous</div><div class="value"><?= h((string)$summaryCounts['ambiguous']) ?></div></div>
        <div class="stat"><div class="muted small">Unmatched</div><div class="value"><?= h((string)$summaryCounts['unmatched']) ?></div></div>
    </div>
</div>

<div class="card">
    <form method="get" class="toolbar">
        <div class="grow">
            <label for="search" class="small muted">Search</label>
            <input id="search" type="text" name="search" value="<?= h($search) ?>" placeholder="Search Discord user, nickname, RSN or ID">
        </div>
        <div class="w-compact">
            <label for="status" class="small muted">Status</label>
            <select id="status" name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="manual" <?= $statusFilter === 'manual' ? 'selected' : '' ?>>Manual Mapping</option>
                <option value="nickname" <?= $statusFilter === 'nickname' ? 'selected' : '' ?>>Nickname Match Available</option>
                <option value="ambiguous" <?= $statusFilter === 'ambiguous' ? 'selected' : '' ?>>Ambiguous Nickname Match</option>
                <option value="unmatched" <?= $statusFilter === 'unmatched' ? 'selected' : '' ?>>Unmatched</option>
            </select>
        </div>
        <div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </div>
        <div>
            <a class="btn-secondary" href="/admin/user-mappings.php">Reset</a>
        </div>
    </form>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
<datalist id="clan-member-options">
    <?php foreach ($clanMembers as $member): ?>
        <?php $optionLabel = (string)$member['rsn'] . (!empty($member['rank_name']) ? ' (' . (string)$member['rank_name'] . ')' : ''); ?>
        <option value="<?= h($optionLabel) ?>"></option>
    <?php endforeach; ?>
</datalist>

    <div class="table-actions">
        <div class="hint">Showing <?= h((string)count($discordMembers)) ?> result<?= count($discordMembers) === 1 ? '' : 's' ?>. Blank selections are treated as runtime-only nickname fallback.</div>
        <button class="btn-primary" type="submit">Save User Mappings</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Discord User</th>
                <th>Nickname</th>
                <th>Current Roles</th>
                <th>Saved Mapping</th>
                <th>Nickname Match Preview</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$discordMembers): ?>
            <tr><td colspan="6" class="muted">No Discord members matched the current filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($discordMembers as $row):
            $discordMember = $row['summary'];
            $manual = $row['manual'];
            $nicknameMatch = $row['nickname_match'];
            $statusKey = $row['status_key'];
            $meta = $statusMeta[$statusKey];
            $userId = (string)$discordMember['user_id'];
            $nickname = (string)$discordMember['nickname'];
            $selectedMemberId = $manual ? (string)$manual['member_id'] : '';
            $currentRoles = $row['current_roles'] ?? [];
        ?>
            <tr>
                <td>
                    <img class="avatar" src="<?= h($discordMember['avatar_url']) ?>" alt="">
                    <div class="stack" style="display:inline-grid; vertical-align:middle;">
                        <strong><?= h($discordMember['display_name']) ?></strong>
                        <span class="small muted">@<?= h($discordMember['username']) ?></span>
                        <span class="small muted mono"><?= h($userId) ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($nickname !== ''): ?>
                        <div><?= h($nickname) ?></div>
                        <div class="small muted mono">Normalised: <?= h(normalise_match_source($nickname)) ?></div>
                    <?php else: ?>
                        <span class="muted">No nickname set</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($currentRoles): ?>
                        <div class="role-chip-list">
                            <?php foreach ($currentRoles as $currentRole): ?>
                                <span class="role-chip<?= !empty($currentRole['managed']) ? ' role-chip-managed' : '' ?>"><?= h((string)$currentRole['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="small muted" style="margin-top:6px"><?= h((string)count($currentRoles)) ?> role<?= count($currentRoles) === 1 ? '' : 's' ?></div>
                    <?php else: ?>
                        <span class="muted">No current roles</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="member-picker">
                        <?php
                            $selectedLabel = '';
                            if ($manual) {
                                $selectedLabel = (string)$manual['rsn_cache'];
                                if ($selectedMemberId !== '') {
                                    foreach ($clanMembers as $memberOption) {
                                        if ((string)$memberOption['id'] === $selectedMemberId) {
                                            $selectedLabel = (string)$memberOption['rsn'] . (!empty($memberOption['rank_name']) ? ' (' . (string)$memberOption['rank_name'] . ')' : '');
                                            break;
                                        }
                                    }
                                }
                            }
                        ?>
                        <input
                            type="text"
                            list="clan-member-options"
                            class="member-combobox"
                            data-hidden-target="member-hidden-<?= h($userId) ?>"
                            placeholder="Search and select RSN or leave blank"
                            value="<?= h($selectedLabel) ?>"
                            autocomplete="off"
                        >
                        <input type="hidden" id="member-hidden-<?= h($userId) ?>" name="member_id[<?= h($userId) ?>]" value="<?= h($selectedMemberId) ?>">
                    </div>
                    <?php if ($manual): ?>
                        <div class="small muted" style="margin-top:6px">Saved override: <?= h((string)$manual['rsn_cache']) ?></div>
                    <?php else: ?>
                        <div class="small muted" style="margin-top:6px">No manual override saved</div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($nicknameMatch): ?>
                        <div><?= h((string)$nicknameMatch['rsn']) ?><?= !empty($nicknameMatch['rank_name']) ? ' (' . h((string)$nicknameMatch['rank_name']) . ')' : '' ?></div>
                        <div class="small muted mono">Matched via normalised name: <?= h((string)$nicknameMatch['rsn_normalised']) ?></div>
                        <div class="small muted">
                            <?php if (($row['nickname_match_type'] ?? '') === 'exact_compact'): ?>Space-insensitive exact match<?php elseif (($row['nickname_match_type'] ?? '') === 'contains'): ?>Token/contains match<?php else: ?>Exact match<?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="muted">No fallback match</span>
                    <?php endif; ?>
                </td>
                <td><span class="status <?= h($meta['class']) ?> nowrap"><?= h($meta['label']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-actions">
        <div class="hint">Tip: use <strong>Nickname Match Available</strong> to review likely matches quickly, then save only the ones that need a manual override.</div>
        <button class="btn-primary" type="submit">Save User Mappings</button>
    </div>
</form>


<script>
const clanMemberLookup = Object.freeze({
<?php
$lookupLines = [];
foreach ($clanMembers as $member) {
    $optionLabel = (string)$member['rsn'] . (!empty($member['rank_name']) ? ' (' . (string)$member['rank_name'] . ')' : '');
    $keys = array_unique(array_filter([
        $optionLabel,
        (string)$member['rsn'],
        (string)$member['rsn_normalised'],
    ]));
    foreach ($keys as $key) {
        $lookupLines[] = json_encode(mb_strtolower((string)$key, 'UTF-8')) . ': ' . json_encode((string)$member['id']);
    }
}
echo implode(",
", $lookupLines);
?>
});

document.addEventListener('input', function (event) {
    if (!event.target.classList.contains('member-combobox')) {
        return;
    }

    const input = event.target;
    const hiddenTarget = input.getAttribute('data-hidden-target');
    const hidden = hiddenTarget ? document.getElementById(hiddenTarget) : null;
    if (!hidden) {
        return;
    }

    const key = input.value.trim().toLowerCase();
    hidden.value = clanMemberLookup[key] || '';
});

document.addEventListener('change', function (event) {
    if (!event.target.classList.contains('member-combobox')) {
        return;
    }

    const input = event.target;
    const hiddenTarget = input.getAttribute('data-hidden-target');
    const hidden = hiddenTarget ? document.getElementById(hiddenTarget) : null;
    if (!hidden) {
        return;
    }

    const key = input.value.trim().toLowerCase();
    hidden.value = clanMemberLookup[key] || '';
});
</script>

<style>
.role-chip-list { display:flex; flex-wrap:wrap; gap:6px; }
.role-chip {
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    background:#1f2937;
    border:1px solid #374151;
    color:#e5e7eb;
    font-size:12px;
    line-height:1.2;
}
.role-chip-managed {
    background:#1e3a8a;
    border-color:#2563eb;
}
</style>

<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
