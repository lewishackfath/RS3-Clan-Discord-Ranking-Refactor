<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$clanId = (int)env('CLAN_ID', '1');
$guildId = (string)env('DISCORD_GUILD_ID', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $submittedMappings = $_POST['member_id'] ?? [];

        $delete = $pdo->prepare('DELETE FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id AND discord_user_id = :user_id');
        $upsert = $pdo->prepare('INSERT INTO discord_user_mappings (clan_id, discord_guild_id, discord_user_id, member_id, rsn_cache, discord_username_cache, discord_nickname_cache)
            VALUES (:clan_id, :guild_id, :user_id, :member_id, :rsn_cache, :username_cache, :nickname_cache)
            ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), rsn_cache = VALUES(rsn_cache), discord_username_cache = VALUES(discord_username_cache), discord_nickname_cache = VALUES(discord_nickname_cache)');

        $membersStmt = $pdo->prepare('SELECT id, rsn FROM clan_members WHERE clan_id = :clan_id AND is_active = 1');
        $membersStmt->execute(['clan_id' => $clanId]);
        $clanMembersById = [];
        foreach ($membersStmt->fetchAll() as $member) {
            $clanMembersById[(string)$member['id']] = $member;
        }

        $discordMembers = [];
        foreach (discord_list_guild_members($guildId) as $member) {
            $summary = discord_format_member_summary($member);
            $discordMembers[$summary['user_id']] = $summary;
        }

        foreach ($submittedMappings as $userId => $memberId) {
            $userId = (string)$userId;
            $memberId = trim((string)$memberId);
            if ($memberId === '') {
                $delete->execute(['clan_id' => $clanId, 'guild_id' => $guildId, 'user_id' => $userId]);
                continue;
            }
            if (!isset($clanMembersById[$memberId])) {
                continue;
            }
            $discordMember = $discordMembers[$userId] ?? ['username' => '', 'nickname' => ''];
            $upsert->execute([
                'clan_id' => $clanId,
                'guild_id' => $guildId,
                'user_id' => $userId,
                'member_id' => (int)$memberId,
                'rsn_cache' => (string)$clanMembersById[$memberId]['rsn'],
                'username_cache' => (string)($discordMember['username'] ?? ''),
                'nickname_cache' => (string)($discordMember['nickname'] ?? ''),
            ]);
        }

        flash('success', 'User mappings saved. Blank selections were not stored and will fall back to nickname matching at runtime.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/user-mappings.php');
}

$memberRows = discord_list_guild_members($guildId);
$discordMembers = [];
foreach ($memberRows as $row) {
    $summary = discord_format_member_summary($row);
    $discordMembers[] = $summary;
}
usort($discordMembers, static fn(array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

$clanStmt = $pdo->prepare('SELECT id, rsn, rank_name, rsn_normalised FROM clan_members WHERE clan_id = :clan_id AND is_active = 1 ORDER BY rsn ASC');
$clanStmt->execute(['clan_id' => $clanId]);
$clanMembers = $clanStmt->fetchAll();

$manualStmt = $pdo->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = :clan_id AND discord_guild_id = :guild_id');
$manualStmt->execute(['clan_id' => $clanId, 'guild_id' => $guildId]);
$manualMappings = [];
foreach ($manualStmt->fetchAll() as $row) {
    $manualMappings[(string)$row['discord_user_id']] = $row;
}

$memberByNormalisedRsn = [];
foreach ($clanMembers as $member) {
    $memberByNormalisedRsn[(string)$member['rsn_normalised']] = $member;
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>User Mappings</h2>
    <p class="muted">Only manual selections are stored here. If a user has no saved mapping, runtime logic should fall back to nickname searching.</p>
</div>

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
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
