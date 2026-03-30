<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$roleResponse = bot_service_request('/guild/summary');
$roles = (($roleResponse['status'] ?? 500) === 200 && is_array($roleResponse['json'])) ? ($roleResponse['json']['roles'] ?? []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $rankRoles = $_POST['rank_role'] ?? [];
    $createRoleNames = $_POST['create_role_name'] ?? [];
    $enabled = $_POST['is_enabled'] ?? [];

    $stmt = db()->prepare('INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE discord_role_id = VALUES(discord_role_id), discord_role_name_cache = VALUES(discord_role_name_cache), is_enabled = VALUES(is_enabled), updated_at = CURRENT_TIMESTAMP');

    foreach ($rankRoles as $rankName => $roleId) {
        $roleId = trim((string)$roleId);
        $roleName = null;

        $requestedRoleName = trim((string)($createRoleNames[$rankName] ?? ''));
        if ($requestedRoleName !== '') {
            $createResponse = bot_service_request('/guild/roles', 'POST', ['name' => $requestedRoleName]);
            if (($createResponse['status'] ?? 500) === 201 && is_array($createResponse['json'])) {
                $roleId = (string)$createResponse['json']['id'];
                $roleName = (string)$createResponse['json']['name'];
                $roles[] = $createResponse['json'];
            } else {
                throw new RuntimeException('Failed to create Discord role for ' . $rankName);
            }
        }

        foreach ($roles as $role) {
            if ((string)$role['id'] === $roleId) {
                $roleName = (string)$role['name'];
                break;
            }
        }
        $stmt->execute([
            (int)env('CLAN_ID', '1'),
            (string)$rankName,
            $roleId !== '' ? $roleId : null,
            $roleName,
            isset($enabled[$rankName]) ? 1 : 0,
        ]);
    }

    flash('success', 'Role mappings saved.');
    redirect('/admin/role-mappings.php');
}

$stmt = db()->prepare('SELECT * FROM rs_rank_mappings WHERE clan_id = ? ORDER BY FIELD(rs_rank_name, "Recruit", "Corporal", "Sergeant", "Lieutenant", "Captain", "General", "Coordinator", "Overseer", "Deputy Owner", "Owner"), rs_rank_name');
$stmt->execute([(int)env('CLAN_ID', '1')]);
$mappings = $stmt->fetchAll();

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>RS Rank to Discord Role Mapping</h2>
    <p class="muted">Map each RuneScape clan rank to a Discord role. These mappings are stored per clan.</p>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
    <table>
        <thead>
            <tr>
                <th>RS Rank</th>
                <th>Discord Role</th>
                <th>Create New Role</th>
                <th>Enabled</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mappings as $mapping): ?>
            <tr>
                <td>
                    <strong><?= h($mapping['rs_rank_name']) ?></strong>
                </td>
                <td>
                    <select name="rank_role[<?= h($mapping['rs_rank_name']) ?>]">
                        <option value="">-- No role selected --</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= h($role['id']) ?>" <?= (string)$mapping['discord_role_id'] === (string)$role['id'] ? 'selected' : '' ?>>
                                <?= h($role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="create_role_name[<?= h($mapping['rs_rank_name']) ?>]" placeholder="Optional new role name"></td>
                <td>
                    <label>
                        <input type="checkbox" name="is_enabled[<?= h($mapping['rs_rank_name']) ?>]" value="1" <?= !empty($mapping['is_enabled']) ? 'checked' : '' ?>>
                        Active
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top: 16px;"><button class="btn-primary" type="submit">Save Role Mappings</button></p>
</form>
<?php require_once __DIR__ . '/../../app/views/footer.php';
