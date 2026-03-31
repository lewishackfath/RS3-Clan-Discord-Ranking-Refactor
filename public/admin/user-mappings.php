<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['discord_user_mappings', 'clan_members']);

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();

    try {
        $discordMembers = discord_list_guild_members($guildId);
        $memberChoices = $_POST['member_id'] ?? [];

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
        foreach ($discordMembers as $discordMember) {
            $summary = discord_format_member_summary($discordMember);
            $userId = (string)$summary['user_id'];
            $memberId = trim((string)($memberChoices[$userId] ?? ''));

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

        flash('success', 'User mappings saved. Manual mappings updated: ' . $saved . '. Cleared mappings: ' . $cleared . '. Blank selections remain runtime-only nickname fallbacks.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/user-mappings.php');
}

$discordMembers = [];
$manualMappings = [];
$clanMembers = [];
$memberByNormalisedRsn = [];
$summaryCounts = ['all' => 0, 'manual' => 0, 'nickname' => 0, 'unmatched' => 0];

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = ['all', 'manual', 'nickname', 'unmatched'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

if (!$missingTables) {
    $discordMembersRaw = discord_list_guild_members($guildId);

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

        $userId = (string)$summary['user_id'];
        $manual = $manualMappings[$userId] ?? null;
        $nickname = (string)$summary['nickname'];
        $username = (string)$summary['username'];
        $displayName = (string)$summary['display_name'];

        $nicknameMatch = null;
        if ($nickname !== '') {
            $nicknameMatch = $memberByNormalisedRsn[normalise_match_source($nickname)] ?? null;
        }
        if ($nicknameMatch === null && $displayName !== '') {
            $nicknameMatch = $memberByNormalisedRsn[normalise_match_source($displayName)] ?? null;
        }
        if ($nicknameMatch === null && $username !== '') {
            $nicknameMatch = $memberByNormalisedRsn[normalise_match_source($username)] ?? null;
        }

        $statusKey = $manual ? 'manual' : ($nicknameMatch ? 'nickname' : 'unmatched');
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

        $discordMembers[] = [
            'summary' => $summary,
            'manual' => $manual,
            'nickname_match' => $nicknameMatch,
            'status_key' => $statusKey,
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
    <div class="table-actions">
        <div class="hint">Showing <?= h((string)count($discordMembers)) ?> result<?= count($discordMembers) === 1 ? '' : 's' ?>. Blank selections are treated as runtime-only nickname fallback.</div>
        <button class="btn-primary" type="submit">Save User Mappings</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Discord User</th>
                <th>Nickname</th>
                <th>Saved Mapping</th>
                <th>Nickname Match Preview</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$discordMembers): ?>
            <tr><td colspan="5" class="muted">No Discord members matched the current filters.</td></tr>
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
                    <div class="member-picker">
                        <input
                            type="text"
                            class="mapping-search"
                            data-target="member-select-<?= h($userId) ?>"
                            placeholder="Search RSN or rank within dropdown"
                            value=""
                            autocomplete="off"
                        >
                        <select id="member-select-<?= h($userId) ?>" name="member_id[<?= h($userId) ?>]" class="member-select">
                            <option value="">-- No manual mapping --</option>
                            <?php foreach ($clanMembers as $member):
                                $optionLabel = (string)$member['rsn'] . (!empty($member['rank_name']) ? ' (' . (string)$member['rank_name'] . ')' : '');
                            ?>
                                <option
                                    value="<?= h((string)$member['id']) ?>"
                                    data-search="<?= h(mb_strtolower($optionLabel . ' ' . (string)$member['rsn_normalised'], 'UTF-8')) ?>"
                                    <?= $selectedMemberId === (string)$member['id'] ? 'selected' : '' ?>
                                >
                                    <?= h($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
document.addEventListener('input', function (event) {
    if (!event.target.classList.contains('mapping-search')) {
        return;
    }

    const input = event.target;
    const selectId = input.getAttribute('data-target');
    const select = document.getElementById(selectId);
    if (!select) {
        return;
    }

    const term = input.value.trim().toLowerCase();
    for (const option of select.options) {
        if (option.value === '') {
            option.hidden = false;
            continue;
        }
        const haystack = (option.dataset.search || option.text || '').toLowerCase();
        option.hidden = term !== '' && !haystack.includes(term);
    }

    const selected = select.options[select.selectedIndex];
    if (selected && selected.hidden) {
        select.value = '';
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
