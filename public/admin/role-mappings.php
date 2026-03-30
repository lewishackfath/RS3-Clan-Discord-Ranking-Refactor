<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $roles = discord_get_guild_roles($guildId);
        $roleMap = discord_role_map($roles);
        $selectedRoles = $_POST['discord_role_id'] ?? [];
        $newRoleNames = $_POST['new_role_name'] ?? [];
        $enabledRows = $_POST['is_enabled'] ?? [];

        $stmt = $pdo->prepare('UPDATE rs_rank_mappings SET discord_role_id = :role_id, discord_role_name_cache = :role_name, is_enabled = :is_enabled WHERE clan_id = :clan_id AND rs_rank_name = :rank_name');

        foreach ($selectedRoles as $rankName => $roleId) {
            $rankName = (string)$rankName;
            $roleId = trim((string)$roleId);
            $newRoleName = trim((string)($newRoleNames[$rankName] ?? ''));
            $isEnabled = isset($enabledRows[$rankName]) ? 1 : 0;

            if ($roleId === '' && $newRoleName !== '') {
                $created = discord_create_role($guildId, $newRoleName);
                $roleId = (string)$created['id'];
                $roleMap[$roleId] = $created;
            }

            $stmt->execute([
                'role_id' => $roleId !== '' ? $roleId : null,
                'role_name' => $roleId !== '' ? (string)($roleMap[$roleId]['name'] ?? '') : null,
                'is_enabled' => $isEnabled,
                'clan_id' => $clanId,
                'rank_name' => $rankName,
            ]);
        }

        flash('success', 'Role mappings saved.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/role-mappings.php');
}

$discordRoles = discord_get_guild_roles($guildId);
usort($discordRoles, static fn(array $a, array $b): int => (int)$b['position'] <=> (int)$a['position']);

$stmt = $pdo->prepare('SELECT * FROM rs_rank_mappings WHERE clan_id = :clan_id ORDER BY id ASC');
$stmt->execute(['clan_id' => $clanId]);
$rankMappings = $stmt->fetchAll();

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>RuneScape Rank to Discord Role Mapping</h2>
    <p class="muted">Each clan rank can point to an existing server role or a brand new role that will be created on save.</p>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
    <table>
        <thead>
            <tr>
                <th>RS Rank</th>
                <th>Existing Role</th>
                <th>Or Create New Role</th>
                <th>Enabled</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rankMappings as $row): ?>
            <tr>
                <td><strong><?= h((string)$row['rs_rank_name']) ?></strong></td>
                <td>
                    <select name="discord_role_id[<?= h((string)$row['rs_rank_name']) ?>]">
                        <option value="">-- No role selected --</option>
                        <?php foreach ($discordRoles as $role): ?>
                            <?php if ((string)$role['name'] === '@everyone') continue; ?>
                            <option value="<?= h((string)$role['id']) ?>" <?= (string)($row['discord_role_id'] ?? '') === (string)$role['id'] ? 'selected' : '' ?>>
                                <?= h((string)$role['name']) ?> (position <?= h((string)$role['position']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="new_role_name[<?= h((string)$row['rs_rank_name']) ?>]" placeholder="Create only if existing role left blank"></td>
                <td><label><input type="checkbox" name="is_enabled[<?= h((string)$row['rs_rank_name']) ?>]" <?= (int)$row['is_enabled'] === 1 ? 'checked' : '' ?>> Enabled</label></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:16px"><button class="btn-primary" type="submit">Save Role Mappings</button></p>
</form>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
