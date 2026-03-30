<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $selections = $_POST['member_id'] ?? [];

    $deleteStmt = db()->prepare('DELETE FROM discord_user_mappings WHERE clan_id = ? AND discord_guild_id = ? AND discord_user_id = ?');
    $upsertStmt = db()->prepare('INSERT INTO discord_user_mappings (clan_id, discord_guild_id, discord_user_id, member_id, rsn_cache, discord_username_cache, discord_nickname_cache)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), rsn_cache = VALUES(rsn_cache), discord_username_cache = VALUES(discord_username_cache), discord_nickname_cache = VALUES(discord_nickname_cache), updated_at = CURRENT_TIMESTAMP');

    $memberMap = [];
    $memberStmt = db()->prepare('SELECT id, rsn FROM clan_members WHERE clan_id = ?');
    $memberStmt->execute([$clanId]);
    foreach ($memberStmt->fetchAll() as $memberRow) {
        $memberMap[(string)$memberRow['id']] = $memberRow;
    }

    $serviceResponse = bot_service_request('/guild/members');
    $discordMembers = (($serviceResponse['status'] ?? 500) === 200 && is_array($serviceResponse['json'])) ? ($serviceResponse['json']['members'] ?? []) : [];
    $discordMemberMap = [];
    foreach ($discordMembers as $discordMember) {
        $discordMemberMap[(string)$discordMember['user_id']] = $discordMember;
    }

    foreach ($selections as $discordUserId => $memberId) {
        $discordUserId = (string)$discordUserId;
        $memberId = trim((string)$memberId);
        if ($memberId === '' || !isset($memberMap[$memberId])) {
            $deleteStmt->execute([$clanId, $guildId, $discordUserId]);
            continue;
        }

        $discordMember = $discordMemberMap[$discordUserId] ?? ['username' => '', 'nickname' => ''];
        $member = $memberMap[$memberId];
        $upsertStmt->execute([
            $clanId,
            $guildId,
            $discordUserId,
            (int)$member['id'],
            (string)$member['rsn'],
            (string)($discordMember['username'] ?? ''),
            (string)($discordMember['nickname'] ?? ''),
        ]);
    }

    flash('success', 'User mappings saved. Blank selections remain unmanaged and will use nickname fallback at runtime.');
    redirect('/admin/user-mappings.php');
}

$membersStmt = db()->prepare('SELECT id, rsn, rsn_normalised, rank_name FROM clan_members WHERE clan_id = ? AND is_active = 1 ORDER BY rsn');
$membersStmt->execute([$clanId]);
$clanMembers = $membersStmt->fetchAll();

$memberById = [];
$memberByNormalisedRsn = [];
foreach ($clanMembers as $member) {
    $memberById[(string)$member['id']] = $member;
    $memberByNormalisedRsn[(string)$member['rsn_normalised']] = $member;
}

$mapStmt = db()->prepare('SELECT * FROM discord_user_mappings WHERE clan_id = ? AND discord_guild_id = ?');
$mapStmt->execute([$clanId, $guildId]);
$manualMappings = [];
foreach ($mapStmt->fetchAll() as $row) {
    $manualMappings[(string)$row['discord_user_id']] = $row;
}

$serviceResponse = bot_service_request('/guild/members');
$discordMembers = (($serviceResponse['status'] ?? 500) === 200 && is_array($serviceResponse['json'])) ? ($serviceResponse['json']['members'] ?? []) : [];

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>User Mappings</h2>
    <p class="muted">Only manual mappings are saved here. If left blank, the bot will attempt nickname matching at runtime.</p>
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
            $nickname = (string)($discordMember['nickname'] ?? '');
            $normalised = normalise_rsn($nickname !== '' ? $nickname : (string)$discordMember['display_name']);
            $nicknameMatch = $memberByNormalisedRsn[$normalised] ?? null;
            $status = $manual ? 'Manual Mapping' : ($nicknameMatch ? 'Nickname Match Available' : 'Unmapped');
        ?>
            <tr>
                <td>
                    <strong><?= h($discordMember['display_name']) ?></strong><br>
                    <span class="small muted">@<?= h($discordMember['username']) ?></span>
                </td>
                <td><?= h($nickname !== '' ? $nickname : '—') ?></td>
                <td>
                    <select name="member_id[<?= h($userId) ?>]">
                        <option value="">-- No manual mapping --</option>
                        <?php foreach ($clanMembers as $member): ?>
                            <option value="<?= h((string)$member['id']) ?>" <?= $manual && (string)$manual['member_id'] === (string)$member['id'] ? 'selected' : '' ?>>
                                <?= h($member['rsn']) ?><?= $member['rank_name'] ? ' (' . h($member['rank_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <?php if ($nicknameMatch): ?>
                        <?= h($nicknameMatch['rsn']) ?>
                    <?php else: ?>
                        <span class="muted">No match</span>
                    <?php endif; ?>
                </td>
                <td><?= h($status) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top: 16px;"><button class="btn-primary" type="submit">Save User Mappings</button></p>
</form>
<?php require_once __DIR__ . '/../../app/views/footer.php';
