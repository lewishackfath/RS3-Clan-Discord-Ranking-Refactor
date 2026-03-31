<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['discord_role_flags', 'rs_rank_mappings']);

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $discordRoles = discord_get_guild_roles($guildId);
        $isBotFlags = is_array($_POST['is_bot_role'] ?? null) ? $_POST['is_bot_role'] : [];
        $protectedFlags = is_array($_POST['is_protected_role'] ?? null) ? $_POST['is_protected_role'] : [];

        $upsert = $pdo->prepare('INSERT INTO discord_role_flags (discord_guild_id, discord_role_id, role_name_cache, position_cache, is_bot_role, is_protected_role)
            VALUES (:guild_id, :role_id, :role_name, :position_cache, :is_bot_role, :is_protected_role)
            ON DUPLICATE KEY UPDATE role_name_cache = VALUES(role_name_cache), position_cache = VALUES(position_cache), is_bot_role = VALUES(is_bot_role), is_protected_role = VALUES(is_protected_role)');

        foreach ($discordRoles as $role) {
            if ((string)$role['name'] === '@everyone') {
                continue;
            }
            $roleId = (string)$role['id'];
            $isManaged = !empty($role['managed']);
            $upsert->execute([
                'guild_id' => $guildId,
                'role_id' => $roleId,
                'role_name' => (string)$role['name'],
                'position_cache' => (int)$role['position'],
                'is_bot_role' => $isManaged || isset($isBotFlags[$roleId]) ? 1 : 0,
                'is_protected_role' => isset($protectedFlags[$roleId]) ? 1 : 0,
            ]);
        }

        flash('success', 'Role management settings saved.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/roles.php');
}

$discordRoles = [];
$existingFlags = [];
$mappedRoleNames = [];

if (!$missingTables) {
    $discordRoles = discord_get_guild_roles($guildId);
    usort($discordRoles, static fn(array $a, array $b): int => (int)$b['position'] <=> (int)$a['position']);

    $stmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
    $stmt->execute(['guild_id' => $guildId]);
    foreach ($stmt->fetchAll() as $row) {
        $existingFlags[(string)$row['discord_role_id']] = $row;
    }

    $mapStmt = $pdo->prepare('SELECT rs_rank_name, discord_role_id FROM rs_rank_mappings WHERE clan_id = :clan_id AND discord_role_id IS NOT NULL AND discord_role_id <> ""');
    $mapStmt->execute(['clan_id' => $clanId]);
    foreach ($mapStmt->fetchAll() as $row) {
        $mappedRoleNames[(string)$row['discord_role_id']][] = (string)$row['rs_rank_name'];
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Role Management</h2>
    <p class="muted">Use this page to classify Discord roles. “Is Bot” is forced on for Discord-managed or integration-managed roles. Protected roles are roles that later sync logic must never remove from members.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
    <table>
        <thead>
            <tr>
                <th>Role</th>
                <th>Position</th>
                <th>Managed</th>
                <th>Rank Mapping</th>
                <th>Is Bot</th>
                <th>Protected Role</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($discordRoles as $role):
            if ((string)$role['name'] === '@everyone') continue;
            $roleId = (string)$role['id'];
            $flags = $existingFlags[$roleId] ?? null;
            $mappedRanks = $mappedRoleNames[$roleId] ?? [];
            $isManaged = !empty($role['managed']);
            $isBot = $isManaged || ($flags && (int)$flags['is_bot_role'] === 1);
        ?>
            <tr>
                <td><strong><?= h((string)$role['name']) ?></strong><br><span class="small muted"><?= h($roleId) ?></span></td>
                <td><?= h((string)$role['position']) ?></td>
                <td><?= $isManaged ? 'Yes' : 'No' ?></td>
                <td><?= $mappedRanks ? h(implode(', ', $mappedRanks)) : '<span class="muted">—</span>' ?></td>
                <td>
                    <label>
                        <input type="checkbox" name="is_bot_role[<?= h($roleId) ?>]" <?= $isBot ? 'checked' : '' ?> <?= $isManaged ? 'disabled' : '' ?>>
                        Is Bot
                    </label>
                    <?php if ($isManaged): ?>
                        <div class="small muted">Forced on because this role is Discord-managed.</div>
                    <?php endif; ?>
                </td>
                <td><label><input type="checkbox" name="is_protected_role[<?= h($roleId) ?>]" <?= $flags && (int)$flags['is_protected_role'] === 1 ? 'checked' : '' ?>> Protected</label></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:16px"><button class="btn-primary" type="submit">Save Role Management</button></p>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
