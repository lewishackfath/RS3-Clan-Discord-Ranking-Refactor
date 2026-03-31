<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['rs_rank_mappings', 'discord_role_flags']);

function parse_new_role_names(string $value): array
{
    $parts = preg_split('/[\r\n,]+/', $value) ?: [];
    $clean = [];
    foreach ($parts as $part) {
        $name = trim($part);
        if ($name !== '') {
            $clean[] = $name;
        }
    }
    return array_values(array_unique($clean));
}

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $roles = discord_get_guild_roles($guildId);
        $roleMap = discord_role_map($roles);
        $selectedRoles = is_array($_POST['discord_role_ids'] ?? null) ? $_POST['discord_role_ids'] : [];
        $newRoleNames = is_array($_POST['new_role_names'] ?? null) ? $_POST['new_role_names'] : [];
        $enabledRows = is_array($_POST['is_enabled'] ?? null) ? $_POST['is_enabled'] : [];

        $deleteStmt = $pdo->prepare('DELETE FROM rs_rank_mappings WHERE clan_id = :clan_id AND rs_rank_name = :rank_name');
        $insertStmt = $pdo->prepare('INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
            VALUES (:clan_id, :rank_name, :role_id, :role_name, :is_enabled)');

        foreach (rs_rank_order() as $rankName) {
            $existingRoleIds = $selectedRoles[$rankName] ?? [];
            if (!is_array($existingRoleIds)) {
                $existingRoleIds = [$existingRoleIds];
            }
            $existingRoleIds = array_values(array_unique(array_filter(array_map('strval', $existingRoleIds), static fn(string $id): bool => trim($id) !== '')));

            $createNames = parse_new_role_names((string)($newRoleNames[$rankName] ?? ''));
            $isEnabled = isset($enabledRows[$rankName]) ? 1 : 0;

            foreach ($createNames as $newRoleName) {
                $created = discord_create_role($guildId, $newRoleName);
                $roleId = (string)$created['id'];
                $roleMap[$roleId] = $created;
                $existingRoleIds[] = $roleId;
            }

            $existingRoleIds = array_values(array_unique($existingRoleIds));

            $deleteStmt->execute([
                'clan_id' => $clanId,
                'rank_name' => $rankName,
            ]);

            if ($existingRoleIds === []) {
                $insertStmt->execute([
                    'clan_id' => $clanId,
                    'rank_name' => $rankName,
                    'role_id' => null,
                    'role_name' => null,
                    'is_enabled' => $isEnabled,
                ]);
                continue;
            }

            foreach ($existingRoleIds as $roleId) {
                $insertStmt->execute([
                    'clan_id' => $clanId,
                    'rank_name' => $rankName,
                    'role_id' => $roleId,
                    'role_name' => (string)($roleMap[$roleId]['name'] ?? ''),
                    'is_enabled' => $isEnabled,
                ]);
            }
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

    $stmt = $pdo->prepare('SELECT * FROM rs_rank_mappings WHERE clan_id = :clan_id ORDER BY rs_rank_name ASC, discord_role_name_cache ASC');
    $stmt->execute(['clan_id' => $clanId]);
    foreach ($stmt->fetchAll() as $row) {
        $rankName = (string)$row['rs_rank_name'];
        if (!isset($rankMappings[$rankName])) {
            $rankMappings[$rankName] = [
                'rs_rank_name' => $rankName,
                'discord_role_ids' => [],
                'discord_role_names' => [],
                'is_enabled' => (int)$row['is_enabled'] === 1 ? 1 : 0,
            ];
        }
        if (!empty($row['discord_role_id'])) {
            $rankMappings[$rankName]['discord_role_ids'][] = (string)$row['discord_role_id'];
            $rankMappings[$rankName]['discord_role_names'][] = (string)($row['discord_role_name_cache'] ?? '');
        }
        if ((int)$row['is_enabled'] === 1) {
            $rankMappings[$rankName]['is_enabled'] = 1;
        }
    }

    try {
        $readiness = validate_bot_readiness($guildId);
        $botHighestPosition = (int)($readiness['bot_highest_role']['position'] ?? -1);
        foreach ($rankMappings as $rankName => $row) {
            foreach (($row['discord_role_ids'] ?? []) as $roleId) {
                $role = $readiness['role_map'][$roleId] ?? null;
                if ($role && (int)$role['position'] >= $botHighestPosition) {
                    $roleWarnings[$rankName][] = (string)$role['name'] . ' sits at or above the bot role and cannot be managed.';
                }
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
    <p class="muted">Each clan rank can map to one or more Discord roles. Guest and Clan Member are included so you can define the general non-ranked member roles as well.</p>
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
                    <th>Mapped Discord Roles</th>
                    <th>Create New Role(s)</th>
                    <th>Enabled</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (rs_rank_order() as $rankName):
                $row = $rankMappings[$rankName] ?? ['rs_rank_name' => $rankName, 'discord_role_ids' => [], 'discord_role_names' => [], 'is_enabled' => 1];
                $selected = array_map('strval', $row['discord_role_ids'] ?? []);
            ?>
                <tr>
                    <td>
                        <strong><?= h($rankName) ?></strong>
                        <?php if (!empty($row['discord_role_names'])): ?>
                            <br><span class="small muted">Current: <?= h(implode(', ', array_filter($row['discord_role_names']))) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($roleWarnings[$rankName])): ?>
                            <?php foreach ($roleWarnings[$rankName] as $warning): ?>
                                <br><span class="small" style="color:#fdba74"><?= h($warning) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="discord_role_ids[<?= h($rankName) ?>][]" multiple size="6">
                            <?php foreach ($discordRoles as $role): ?>
                                <?php if ((string)$role['name'] === '@everyone') continue; ?>
                                <option value="<?= h((string)$role['id']) ?>" <?= in_array((string)$role['id'], $selected, true) ? 'selected' : '' ?>>
                                    <?= h((string)$role['name']) ?> (position <?= h((string)$role['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small muted" style="margin-top:6px">Hold Ctrl or Cmd to select multiple roles.</div>
                    </td>
                    <td>
                        <textarea name="new_role_names[<?= h($rankName) ?>]" placeholder="Optional. Add one new role per line, or separate multiple names with commas."></textarea>
                    </td>
                    <td><label><input type="checkbox" name="is_enabled[<?= h($rankName) ?>]" <?= (int)$row['is_enabled'] === 1 ? 'checked' : '' ?>> Enabled</label></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:16px"><button class="btn-primary" type="submit">Save Role Mappings</button></p>
    </form>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
