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
        $memberChoices = is_array($_POST['member_id'] ?? null) ? $_POST['member_id'] : [];

        $clanMembersStmt = $pdo->prepare('SELECT id, rsn, rank_name FROM clan_members WHERE clan_id = :clan_id');
        $clanMembersStmt->execute(['clan_id' => $clanId]);
        $clanMembersById = [];
        foreach ($clanMembersStmt->fetchAll() as $row) {
            $clanMembersById[(string)$row['id']] = $row;
        }

        $upsert = $pdo->prepare('INSERT INTO discord_user_mappings (clan_id, discord_guild_id, discord_user_id, member_id, rsn_cache, discord_username_cache, discord_nickname_cache)
            VALUES (:clan_id, :guild_id, :discord_user_id, :member_id, :rsn_cache, :username_cache, :nickname_cache)
            ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), rsn_cache = VALUES(rsn_cache), discord_username_cache = VALUES(discord_username_cache), discord_nickname_cache = VALUES(discord_nickname_cache)');
        $delete = $pdo->prepare('DELETE FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id AND discord_user_id = :discord_user_id');

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
                'username_cache' => (string)$summary['username'],
                'nickname_cache' => (string)$summary['nickname'],
            ]);
        }

        flash('success', 'User mappings saved. Blank selections remain runtime-only nickname fallbacks.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/user-mappings.php');
}

$discordMembers = [];
$manualMappings = [];
$clanMembers = [];
$memberByNormalisedRsn = [];

if (!$missingTables) {
    $discordMembersRaw = discord_list_guild_members($guildId);
    foreach ($discordMembersRaw as $member) {
        $discordMembers[] = discord_format_member_summary($member);
    }

    usort($discordMembers, static fn(array $a, array $b): int => strcasecmp((string)$a['display_name'], (string)$b['display_name']));

    $stmt = $pdo->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id');
    $stmt->execute(['clan_id' => $clanId, 'guild_id' => $guildId]);
    foreach ($stmt->fetchAll() as $row) {
        $manualMappings[(string)$row['discord_user_id']] = $row;
    }

    $clanStmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id AND is_active = 1 ORDER BY rsn ASC');
    $clanStmt->execute(['clan_id' => $clanId]);
    $clanMembers = $clanStmt->fetchAll() ?: [];
    foreach ($clanMembers as $member) {
        $memberByNormalisedRsn[(string)$member['rsn_normalised']] = $member;
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>User Mappings</h2>
    <p class="muted">Only manual selections are stored here. If a user has no saved mapping, runtime logic should fall back to nickname searching.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
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
        <?php foreach ($discordMembers as $discordMember):
            $userId = (string)$discordMember['user_id'];
            $manual = $manualMappings[$userId] ?? null;
            $nickname = (string)$discordMember['nickname'];
            $fallbackSource = $nickname !== '' ? $nickname : (string)$discordMember['display_name'];
            $nicknameMatch = $memberByNormalisedRsn[normalise_rsn($fallbackSource)] ?? null;
            $status = $manual ? 'Manual Mapping' : ($nicknameMatch ? 'Nickname Match Available' : 'Unmapped');
        ?>
            <tr>
                <td>
                    <img class="avatar" src="<?= h($discordMember['avatar_url']) ?>" alt="">
                    <strong><?= h($discordMember['display_name']) ?></strong><br>
                    <span class="small muted">@<?= h($discordMember['username']) ?><br><?= h($userId) ?></span>
                </td>
                <td><?= h($nickname !== '' ? $nickname : '—') ?></td>
                <td>
                    <select name="member_id[<?= h($userId) ?>]">
                        <option value="">-- No manual mapping --</option>
                        <?php foreach ($clanMembers as $member): ?>
                            <option value="<?= h((string)$member['id']) ?>" <?= $manual && (string)$manual['member_id'] === (string)$member['id'] ? 'selected' : '' ?>>
                                <?= h((string)$member['rsn']) ?><?= !empty($member['rank_name']) ? ' (' . h((string)$member['rank_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <?php if ($nicknameMatch): ?>
                        <?= h((string)$nicknameMatch['rsn']) ?><?= !empty($nicknameMatch['rank_name']) ? ' (' . h((string)$nicknameMatch['rank_name']) . ')' : '' ?>
                    <?php else: ?>
                        <span class="muted">No match</span>
                    <?php endif; ?>
                </td>
                <td><?= h($status) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:16px"><button class="btn-primary" type="submit">Save User Mappings</button></p>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
