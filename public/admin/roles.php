<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $discordRoles = discord_get_guild_roles($guildId);
        $botRoleFlags = $_POST['is_bot_role'] ?? [];
        $protectedFlags = $_POST['is_protected_role'] ?? [];

        $upsert = $pdo->prepare('INSERT INTO discord_role_flags (discord_guild_id, discord_role_id, role_name_cache, position_cache, is_bot_role, is_protected_role)
            VALUES (:guild_id, :role_id, :role_name, :position_cache, :is_bot_role, :is_protected_role)
            ON DUPLICATE KEY UPDATE role_name_cache = VALUES(role_name_cache), position_cache = VALUES(position_cache), is_bot_role = VALUES(is_bot_role), is_protected_role = VALUES(is_protected_role)');

        foreach ($discordRoles as $role) {
            if ((string)$role['name'] === '@everyone') {
                continue;
            }
            $roleId = (string)$role['id'];
            $upsert->execute([
                'guild_id' => $guildId,
                'role_id' => $roleId,
                'role_name' => (string)$role['name'],
                'position_cache' => (int)$role['position'],
                'is_bot_role' => isset($botRoleFlags[$roleId]) ? 1 : 0,
                'is_protected_role' => isset($protectedFlags[$roleId]) ? 1 : 0,
            ]);
        }

        flash('success', 'Role flags saved.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/roles.php');
}

$discordRoles = discord_get_guild_roles($guildId);
usort($discordRoles, static fn(array $a, array $b): int => (int)$b['position'] <=> (int)$a['position']);

$stmt = $pdo->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = :guild_id');
$stmt->execute(['guild_id' => $guildId]);
$existingFlags = [];
foreach ($stmt->fetchAll() as $row) {
    $existingFlags[(string)$row['discord_role_id']] = $row;
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Role Flags</h2>
    <p class="muted">Bot roles are roles this app is allowed to manage. Protected roles are roles that later sync logic must never remove from members.</p>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
    <table>
        <thead>
            <tr>
                <th>Role</th>
                <th>Position</th>
                <th>Managed</th>
                <th>Bot Role</th>
                <th>Protected Role</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($discordRoles as $role):
            if ((string)$role['name'] === '@everyone') continue;
            $roleId = (string)$role['id'];
            $flags = $existingFlags[$roleId] ?? null;
        ?>
            <tr>
                <td><strong><?= h((string)$role['name']) ?></strong><br><span class="small muted"><?= h($roleId) ?></span></td>
                <td><?= h((string)$role['position']) ?></td>
                <td><?= !empty($role['managed']) ? 'Yes' : 'No' ?></td>
                <td><label><input type="checkbox" name="is_bot_role[<?= h($roleId) ?>]" <?= $flags && (int)$flags['is_bot_role'] === 1 ? 'checked' : '' ?>> Bot-managed</label></td>
                <td><label><input type="checkbox" name="is_protected_role[<?= h($roleId) ?>]" <?= $flags && (int)$flags['is_protected_role'] === 1 ? 'checked' : '' ?>> Protected</label></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:16px"><button class="btn-primary" type="submit">Save Role Flags</button></p>
</form>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
