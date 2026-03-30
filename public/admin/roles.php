<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$roleResponse = bot_service_request('/guild/summary');
$roles = (($roleResponse['status'] ?? 500) === 200 && is_array($roleResponse['json'])) ? ($roleResponse['json']['roles'] ?? []) : [];
$guildId = (string)env('DISCORD_GUILD_ID', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $botRoles = $_POST['is_bot_role'] ?? [];
    $protectedRoles = $_POST['is_protected_role'] ?? [];

    $stmt = db()->prepare('INSERT INTO discord_role_flags (discord_guild_id, discord_role_id, role_name_cache, position_cache, is_bot_role, is_protected_role)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE role_name_cache = VALUES(role_name_cache), position_cache = VALUES(position_cache), is_bot_role = VALUES(is_bot_role), is_protected_role = VALUES(is_protected_role), updated_at = CURRENT_TIMESTAMP');

    foreach ($roles as $role) {
        $roleId = (string)$role['id'];
        $stmt->execute([
            $guildId,
            $roleId,
            (string)$role['name'],
            (int)$role['position'],
            isset($botRoles[$roleId]) ? 1 : 0,
            isset($protectedRoles[$roleId]) ? 1 : 0,
        ]);
    }

    flash('success', 'Role flags updated.');
    redirect('/admin/roles.php');
}

$stmt = db()->prepare('SELECT * FROM discord_role_flags WHERE discord_guild_id = ?');
$stmt->execute([$guildId]);
$flags = [];
foreach ($stmt->fetchAll() as $row) {
    $flags[$row['discord_role_id']] = $row;
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Role Flags</h2>
    <p class="muted">Mark roles the app is allowed to manage and roles that must never be stripped from members by future sync logic.</p>
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
        <?php foreach ($roles as $role): $flag = $flags[$role['id']] ?? null; ?>
            <tr>
                <td><?= h($role['name']) ?></td>
                <td><?= h((string)$role['position']) ?></td>
                <td><?= !empty($role['managed']) ? 'Yes' : 'No' ?></td>
                <td><input type="checkbox" name="is_bot_role[<?= h($role['id']) ?>]" value="1" <?= !empty($flag['is_bot_role']) ? 'checked' : '' ?>></td>
                <td><input type="checkbox" name="is_protected_role[<?= h($role['id']) ?>]" value="1" <?= !empty($flag['is_protected_role']) ? 'checked' : '' ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top: 16px;"><button class="btn-primary" type="submit">Save Role Flags</button></p>
</form>
<?php require_once __DIR__ . '/../../app/views/footer.php';
