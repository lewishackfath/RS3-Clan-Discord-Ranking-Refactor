<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['rs_rank_mappings', 'discord_role_flags']);

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $roles = discord_get_guild_roles($guildId);
        $roleMap = discord_role_map($roles);
        $selectedRoles = is_array($_POST['discord_role_id'] ?? null) ? $_POST['discord_role_id'] : [];
        $newRoleNames = is_array($_POST['new_role_name'] ?? null) ? $_POST['new_role_name'] : [];
        $enabledRows = is_array($_POST['is_enabled'] ?? null) ? $_POST['is_enabled'] : [];

        $stmt = $pdo->prepare('UPDATE rs_rank_mappings SET discord_role_id = :role_id, discord_role_name_cache = :role_name, is_enabled = :is_enabled WHERE clan_id = :clan_id AND rs_rank_name = :rank_name');

        foreach (rs_rank_order() as $rankName) {
            $roleId = trim((string)($selectedRoles[$rankName] ?? ''));
            $newRoleName = trim((string)($newRoleNames[$rankName] ?? ''));
            $isEnabled = isset($enabledRows[$rankName]) ? 1 : 0;

            if ($roleId !== '' && $newRoleName !== '') {
                throw new RuntimeException('Choose either an existing role or a new role name for ' . $rankName . ', not both.');
            }

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

$discordRoles = [];
$rankMappings = [];
$readiness = null;
$roleWarnings = [];

if (!$missingTables) {
    $discordRoles = discord_get_guild_roles($guildId);
    usort($discordRoles, static fn(array $a, array $b): int => (int)$b['position'] <=> (int)$a['position']);

    $stmt = $pdo->prepare('SELECT * FROM rs_rank_mappings WHERE clan_id = :clan_id');
    $stmt->execute(['clan_id' => $clanId]);
    foreach ($stmt->fetchAll() as $row) {
        $rankMappings[(string)$row['rs_rank_name']] = $row;
    }

    try {
        $readiness = validate_bot_readiness($guildId);
        $botHighestPosition = (int)($readiness['bot_highest_role']['position'] ?? -1);
        foreach ($rankMappings as $rankName => $row) {
            $roleId = (string)($row['discord_role_id'] ?? '');
            if ($roleId === '') {
                continue;
            }
            $role = $readiness['role_map'][$roleId] ?? null;
            if ($role && (int)$role['position'] >= $botHighestPosition) {
                $roleWarnings[$rankName] = 'Bot role must sit above this role before it can be managed.';
            }
        }
    } catch (Throwable $e) {
        $readiness = ['ok' => false, 'messages' => [$e->getMessage()]];
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>RuneScape Rank to Discord Role Mapping</h2>
    <p class="muted">Each clan rank can point to an existing server role or a brand new role that will be created on save.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
    <?php if ($readiness && !$readiness['ok']): ?>
        <div class="card">
            <span class="status warn">Hierarchy Warning</span>
            <ul>
                <?php foreach (($readiness['messages'] ?? []) as $message): ?>
                    <li><?= h((string)$message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

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
            <?php foreach (rs_rank_order() as $rankName): $row = $rankMappings[$rankName] ?? ['rs_rank_name' => $rankName, 'discord_role_id' => null, 'is_enabled' => 1]; ?>
                <tr>
                    <td>
                        <strong><?= h($rankName) ?></strong>
                        <?php if (isset($roleWarnings[$rankName])): ?>
                            <br><span class="small" style="color:#fdba74"><?= h($roleWarnings[$rankName]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="discord_role_id[<?= h($rankName) ?>]">
                            <option value="">-- No role selected --</option>
                            <?php foreach ($discordRoles as $role): ?>
                                <?php if ((string)$role['name'] === '@everyone') continue; ?>
                                <option value="<?= h((string)$role['id']) ?>" <?= (string)($row['discord_role_id'] ?? '') === (string)$role['id'] ? 'selected' : '' ?>>
                                    <?= h((string)$role['name']) ?> (position <?= h((string)$role['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="new_role_name[<?= h($rankName) ?>]" placeholder="Create only if existing role left blank"></td>
                    <td><label><input type="checkbox" name="is_enabled[<?= h($rankName) ?>]" <?= (int)$row['is_enabled'] === 1 ? 'checked' : '' ?>> Enabled</label></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:16px"><button class="btn-primary" type="submit">Save Role Mappings</button></p>
    </form>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
