<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');

$missingTables = require_tables($pdo, ['guild_settings', 'rs_rank_mappings']);
$guildSettings = [];
$guild = null;
$discordRoles = [];
$scanError = null;

const DISCORD_PERMISSION_ADMINISTRATOR = '8';
const DISCORD_PERMISSION_SERVER_MODERATOR = '1099511644166'; // Kick + Ban + Manage Messages + Moderate Members

function bootstrap_recommended_roles(): array
{
    return [
        ['name' => 'Server Admin', 'permission_mode' => 'administrator', 'permissions' => DISCORD_PERMISSION_ADMINISTRATOR, 'description' => 'Administrator access for trusted server admins.'],
        ['name' => 'Server Moderator', 'permission_mode' => 'moderator', 'permissions' => DISCORD_PERMISSION_SERVER_MODERATOR, 'description' => 'Kick, ban, timeout, and manage messages without role/server settings management.'],
        ['name' => 'Owner', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Deputy Owner', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Overseer', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Coordinator', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Clan Admin', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended Discord equivalent for the RuneScape Admin rank.'],
        ['name' => 'General', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Captain', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Lieutenant', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Sergeant', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Corporal', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Recruit', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Recommended clan hierarchy role.'],
        ['name' => 'Clan Member', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Base member role used by sync mappings.'],
        ['name' => 'Guest', 'permission_mode' => 'none', 'permissions' => null, 'description' => 'Fallback guest role used by sync mappings.'],
    ];
}

function bootstrap_role_index_by_name(array $roles): array
{
    $index = [];
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        $name = trim((string)($role['name'] ?? ''));
        if ($name === '' || $name === '@everyone') {
            continue;
        }
        if (!isset($index[$name])) {
            $index[$name] = $role;
        }
    }
    return $index;
}

function bootstrap_existing_rank_mappings(PDO $pdo, int $clanId): array
{
    $stmt = $pdo->prepare('SELECT rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled
        FROM rs_rank_mappings
        WHERE clan_id = :clan_id
        ORDER BY rs_rank_name ASC, id ASC');
    $stmt->execute(['clan_id' => $clanId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $rankName = (string)($row['rs_rank_name'] ?? '');
        if ($rankName === '') {
            continue;
        }
        if (!isset($out[$rankName])) {
            $out[$rankName] = [
                'role_ids' => [],
                'role_names' => [],
                'is_enabled' => (int)($row['is_enabled'] ?? 1) === 1 ? 1 : 0,
            ];
        }

        $roleId = trim((string)($row['discord_role_id'] ?? ''));
        if ($roleId !== '') {
            $out[$rankName]['role_ids'][] = $roleId;
            $out[$rankName]['role_names'][] = (string)($row['discord_role_name_cache'] ?? '');
        }

        if ((int)($row['is_enabled'] ?? 0) === 1) {
            $out[$rankName]['is_enabled'] = 1;
        }
    }

    return $out;
}

function bootstrap_default_rank_targets(): array
{
    return [
        'Guest' => 'Guest',
        'Clan Member' => 'Clan Member',
        'Recruit' => 'Recruit',
        'Corporal' => 'Corporal',
        'Sergeant' => 'Sergeant',
        'Lieutenant' => 'Lieutenant',
        'Captain' => 'Captain',
        'General' => 'General',
        'Admin' => 'Clan Admin',
        'Coordinator' => 'Coordinator',
        'Overseer' => 'Overseer',
        'Deputy Owner' => 'Deputy Owner',
        'Owner' => 'Owner',
    ];
}

function bootstrap_scan_plan(array $recommendedRoles, array $rolesByName, array $guildSettings, array $rankMappings): array
{
    $roleRows = [];
    $missingRoleNames = [];
    foreach ($recommendedRoles as $definition) {
        $name = (string)$definition['name'];
        $existing = $rolesByName[$name] ?? null;
        $roleRows[] = [
            'name' => $name,
            'permission_mode' => (string)$definition['permission_mode'],
            'description' => (string)$definition['description'],
            'existing' => $existing,
            'will_create' => $existing === null,
        ];
        if ($existing === null) {
            $missingRoleNames[] = $name;
        }
    }

    $settingTargets = [
        'server_admin_role_id' => 'Server Admin',
        'server_moderator_role_id' => 'Server Moderator',
    ];

    $settingRows = [];
    foreach ($settingTargets as $column => $roleName) {
        $currentRoleId = trim((string)($guildSettings[$column] ?? ''));
        $matchedRole = $rolesByName[$roleName] ?? null;
        $settingRows[] = [
            'column' => $column,
            'label' => $roleName,
            'current_role_id' => $currentRoleId,
            'matched_role' => $matchedRole,
            'will_fill' => $currentRoleId === '' && $matchedRole !== null,
        ];
    }

    $mappingRows = [];
    foreach (bootstrap_default_rank_targets() as $rankName => $roleName) {
        $current = $rankMappings[$rankName] ?? ['role_ids' => [], 'role_names' => [], 'is_enabled' => 1];
        $matchedRole = $rolesByName[$roleName] ?? null;
        $hasExactTarget = $matchedRole !== null && in_array((string)$matchedRole['id'], array_map('strval', $current['role_ids']), true);

        $mappingRows[] = [
            'rank_name' => $rankName,
            'target_role_name' => $roleName,
            'matched_role' => $matchedRole,
            'current_role_names' => array_values(array_filter(array_map('strval', $current['role_names']))),
            'will_fill' => $matchedRole !== null && !$hasExactTarget && empty($current['role_ids']),
        ];
    }

    return [
        'roles' => $roleRows,
        'missing_role_names' => $missingRoleNames,
        'settings' => $settingRows,
        'mappings' => $mappingRows,
    ];
}

if (!$missingTables) {
    try {
        $guild = discord_get_guild($guildId);
        $discordRoles = discord_get_guild_roles($guildId);

        $settingsStmt = $pdo->prepare('SELECT * FROM guild_settings WHERE clan_id = :clan_id LIMIT 1');
        $settingsStmt->execute(['clan_id' => $clanId]);
        $guildSettings = $settingsStmt->fetch() ?: [];
    } catch (Throwable $e) {
        $scanError = $e->getMessage();
    }
}

$rolesByName = bootstrap_role_index_by_name($discordRoles);
$rankMappings = (!$missingTables && $scanError === null) ? bootstrap_existing_rank_mappings($pdo, $clanId) : [];
$recommendedRoles = bootstrap_recommended_roles();
$scanPlan = (!$missingTables && $scanError === null) ? bootstrap_scan_plan($recommendedRoles, $rolesByName, $guildSettings, $rankMappings) : [
    'roles' => [],
    'missing_role_names' => [],
    'settings' => [],
    'mappings' => [],
];

if (!$missingTables && $scanError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'deploy_bootstrap') {
        try {
            $guild = discord_get_guild($guildId);
            $discordRoles = discord_get_guild_roles($guildId);
            $rolesByName = bootstrap_role_index_by_name($discordRoles);

            $settingsStmt = $pdo->prepare('SELECT * FROM guild_settings WHERE clan_id = :clan_id LIMIT 1');
            $settingsStmt->execute(['clan_id' => $clanId]);
            $guildSettings = $settingsStmt->fetch() ?: [];
            $rankMappings = bootstrap_existing_rank_mappings($pdo, $clanId);

            $createdRoleNames = [];

            foreach ($recommendedRoles as $definition) {
                $roleName = (string)$definition['name'];
                if (isset($rolesByName[$roleName])) {
                    continue;
                }

                $createdRole = discord_create_role($guildId, $roleName, [
                    'permissions' => $definition['permissions'],
                ]);

                $rolesByName[$roleName] = $createdRole;
                $createdRoleNames[] = $roleName;
            }

            $serverAdminRole = $rolesByName['Server Admin'] ?? null;
            $serverModeratorRole = $rolesByName['Server Moderator'] ?? null;

            $upsertGuildSettings = $pdo->prepare('INSERT INTO guild_settings (
                    clan_id,
                    discord_guild_id,
                    guild_name_cache,
                    server_admin_role_id,
                    server_admin_role_name_cache,
                    server_moderator_role_id,
                    server_moderator_role_name_cache
                ) VALUES (
                    :clan_id,
                    :discord_guild_id,
                    :guild_name_cache,
                    :server_admin_role_id,
                    :server_admin_role_name_cache,
                    :server_moderator_role_id,
                    :server_moderator_role_name_cache
                )
                ON DUPLICATE KEY UPDATE
                    discord_guild_id = VALUES(discord_guild_id),
                    guild_name_cache = VALUES(guild_name_cache),
                    server_admin_role_id = CASE
                        WHEN COALESCE(server_admin_role_id, "") = "" THEN VALUES(server_admin_role_id)
                        ELSE server_admin_role_id
                    END,
                    server_admin_role_name_cache = CASE
                        WHEN COALESCE(server_admin_role_id, "") = "" THEN VALUES(server_admin_role_name_cache)
                        ELSE server_admin_role_name_cache
                    END,
                    server_moderator_role_id = CASE
                        WHEN COALESCE(server_moderator_role_id, "") = "" THEN VALUES(server_moderator_role_id)
                        ELSE server_moderator_role_id
                    END,
                    server_moderator_role_name_cache = CASE
                        WHEN COALESCE(server_moderator_role_id, "") = "" THEN VALUES(server_moderator_role_name_cache)
                        ELSE server_moderator_role_name_cache
                    END');

            $upsertGuildSettings->execute([
                'clan_id' => $clanId,
                'discord_guild_id' => $guildId,
                'guild_name_cache' => (string)($guild['name'] ?? ''),
                'server_admin_role_id' => !empty($guildSettings['server_admin_role_id']) ? (string)$guildSettings['server_admin_role_id'] : (string)($serverAdminRole['id'] ?? ''),
                'server_admin_role_name_cache' => !empty($guildSettings['server_admin_role_id']) ? (string)($guildSettings['server_admin_role_name_cache'] ?? '') : (string)($serverAdminRole['name'] ?? ''),
                'server_moderator_role_id' => !empty($guildSettings['server_moderator_role_id']) ? (string)$guildSettings['server_moderator_role_id'] : (string)($serverModeratorRole['id'] ?? ''),
                'server_moderator_role_name_cache' => !empty($guildSettings['server_moderator_role_id']) ? (string)($guildSettings['server_moderator_role_name_cache'] ?? '') : (string)($serverModeratorRole['name'] ?? ''),
            ]);

            $insertMappingStmt = $pdo->prepare('INSERT INTO rs_rank_mappings (
                    clan_id,
                    rs_rank_name,
                    discord_role_id,
                    discord_role_name_cache,
                    is_enabled
                ) VALUES (
                    :clan_id,
                    :rs_rank_name,
                    :discord_role_id,
                    :discord_role_name_cache,
                    1
                )
                ON DUPLICATE KEY UPDATE
                    discord_role_name_cache = VALUES(discord_role_name_cache),
                    is_enabled = VALUES(is_enabled)');

            $mappingCreates = [];
            foreach (bootstrap_default_rank_targets() as $rankName => $targetRoleName) {
                $matchedRole = $rolesByName[$targetRoleName] ?? null;
                if ($matchedRole === null) {
                    continue;
                }

                $existing = $rankMappings[$rankName] ?? ['role_ids' => []];
                $existingRoleIds = array_map('strval', $existing['role_ids'] ?? []);
                if ($existingRoleIds !== []) {
                    continue;
                }

                $insertMappingStmt->execute([
                    'clan_id' => $clanId,
                    'rs_rank_name' => $rankName,
                    'discord_role_id' => (string)$matchedRole['id'],
                    'discord_role_name_cache' => (string)$matchedRole['name'],
                ]);

                $mappingCreates[] = $rankName . ' → ' . (string)$matchedRole['name'];
            }

            $parts = [];
            $parts[] = $createdRoleNames === []
                ? 'No new roles were required.'
                : 'Created roles: ' . implode(', ', $createdRoleNames) . '.';

            $autoFilledSettings = [];
            if (empty($guildSettings['server_admin_role_id']) && $serverAdminRole !== null) {
                $autoFilledSettings[] = 'Server Admin';
            }
            if (empty($guildSettings['server_moderator_role_id']) && $serverModeratorRole !== null) {
                $autoFilledSettings[] = 'Server Moderator';
            }
            $parts[] = $autoFilledSettings === []
                ? 'Server admin/mod settings were left unchanged.'
                : 'Auto-filled settings: ' . implode(', ', $autoFilledSettings) . '.';

            $parts[] = $mappingCreates === []
                ? 'No default rank mappings needed to be added.'
                : 'Added default rank mappings: ' . implode(', ', $mappingCreates) . '.';

            $parts[] = 'Guest and Clan Member auto-fill is handled through rank mappings because this schema does not have dedicated guest/clan-member guild setting columns.';

            flash('success', implode(' ', $parts));
        } catch (Throwable $e) {
            flash('error', 'Role bootstrap failed: ' . $e->getMessage());
        }

        redirect('/admin/discord-role-bootstrap.php');
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Discord Role Bootstrap</h2>
    <p class="muted">Safe first pass: scans the current guild, creates only missing recommended roles by exact name, leaves existing role permissions untouched, and never reorders roles.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card">
        <span class="status bad">Setup Required</span>
        <p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p>
    </div>
<?php elseif ($scanError !== null): ?>
    <div class="card">
        <span class="status bad">Discord Error</span>
        <p><?= h($scanError) ?></p>
    </div>
<?php else: ?>
    <?php
        $missingCount = count($scanPlan['missing_role_names']);
        $settingFillCount = count(array_filter($scanPlan['settings'], static fn(array $row): bool => !empty($row['will_fill'])));
        $mappingFillCount = count(array_filter($scanPlan['mappings'], static fn(array $row): bool => !empty($row['will_fill'])));
    ?>
    <div class="grid two">
        <div class="card">
            <h3>Scan Summary</h3>
            <table>
                <tbody>
                    <tr><th>Guild</th><td><?= h((string)($guild['name'] ?? 'Unknown Guild')) ?></td></tr>
                    <tr><th>Recommended Roles</th><td><?= h((string)count($recommendedRoles)) ?></td></tr>
                    <tr><th>Already Present</th><td><?= h((string)(count($recommendedRoles) - $missingCount)) ?></td></tr>
                    <tr><th>Missing</th><td><?= $missingCount > 0 ? '<span class="status warn">' . h((string)$missingCount) . '</span>' : '<span class="status ok">0</span>' ?></td></tr>
                    <tr><th>Settings To Auto-Fill</th><td><?= $settingFillCount > 0 ? h((string)$settingFillCount) : '<span class="muted">0</span>' ?></td></tr>
                    <tr><th>Rank Mappings To Add</th><td><?= $mappingFillCount > 0 ? h((string)$mappingFillCount) : '<span class="muted">0</span>' ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Deploy Behaviour</h3>
            <ul>
                <li>Creates only missing roles from the recommended list.</li>
                <li>Applies permissions only when a role is newly created.</li>
                <li>Leaves existing roles, permissions, and order untouched.</li>
                <li>Auto-fills Server Admin / Server Moderator in guild settings only when those settings are currently blank.</li>
                <li>Adds default rank mappings only when a rank currently has no mapped Discord role.</li>
            </ul>
            <form method="post" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
                <input type="hidden" name="action" value="deploy_bootstrap">
                <button class="btn-primary" type="submit">Deploy Recommended Roles</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Recommended Roles</h3>
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Permission Profile</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scanPlan['roles'] as $row): ?>
                    <?php $existing = $row['existing']; ?>
                    <tr>
                        <td>
                            <strong><?= h((string)$row['name']) ?></strong>
                            <?php if ($existing): ?>
                                <br><span class="small muted mono"><?= h((string)($existing['id'] ?? '')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($existing): ?>
                                <span class="status ok">Exists</span>
                            <?php else: ?>
                                <span class="status warn">Will Create</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $mode = (string)$row['permission_mode'];
                                echo $mode === 'administrator'
                                    ? 'Administrator'
                                    : ($mode === 'moderator' ? 'Message/User Moderation' : 'No automatic permissions');
                            ?>
                        </td>
                        <td><?= h((string)$row['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="grid two">
        <div class="card">
            <h3>Guild Settings Auto-Fill</h3>
            <table>
                <thead>
                    <tr>
                        <th>Setting</th>
                        <th>Current</th>
                        <th>Matched Role</th>
                        <th>Deploy Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scanPlan['settings'] as $row): ?>
                        <tr>
                            <td><strong><?= h((string)$row['label']) ?></strong><br><span class="small muted"><?= h((string)$row['column']) ?></span></td>
                            <td>
                                <?php if ((string)$row['current_role_id'] !== ''): ?>
                                    <span class="status ok">Already Set</span><br>
                                    <span class="small muted mono"><?= h((string)$row['current_role_id']) ?></span>
                                <?php else: ?>
                                    <span class="muted">Blank</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['matched_role']): ?>
                                    <?= h((string)($row['matched_role']['name'] ?? '')) ?><br>
                                    <span class="small muted mono"><?= h((string)($row['matched_role']['id'] ?? '')) ?></span>
                                <?php else: ?>
                                    <span class="muted">No exact role match yet</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['will_fill'])): ?>
                                    <span class="status warn">Will Auto-Fill</span>
                                <?php else: ?>
                                    <span class="muted">No change</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>Clan Member</strong><br><span class="small muted">single-select mapping</span></td>
                        <td colspan="3" class="small muted">Handled through <code>rs_rank_mappings</code> because this schema has no dedicated <code>clan_member_role_id</code> column.</td>
                    </tr>
                    <tr>
                        <td><strong>Guest</strong><br><span class="small muted">single-select mapping</span></td>
                        <td colspan="3" class="small muted">Handled through <code>rs_rank_mappings</code> because this schema has no dedicated <code>guest_role_id</code> column.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Default Rank Mapping Bootstrap</h3>
            <table>
                <thead>
                    <tr>
                        <th>RS Rank</th>
                        <th>Target Discord Role</th>
                        <th>Current Mapping</th>
                        <th>Deploy Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scanPlan['mappings'] as $row): ?>
                        <tr>
                            <td><strong><?= h((string)$row['rank_name']) ?></strong></td>
                            <td><?= h((string)$row['target_role_name']) ?></td>
                            <td>
                                <?php if (!empty($row['current_role_names'])): ?>
                                    <?= h(implode(', ', $row['current_role_names'])) ?>
                                <?php else: ?>
                                    <span class="muted">No mapped role</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['will_fill'])): ?>
                                    <span class="status warn">Will Add</span>
                                <?php elseif ($row['matched_role']): ?>
                                    <span class="muted">No change</span>
                                <?php else: ?>
                                    <span class="muted">Target role missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>Organiser</strong></td>
                        <td colspan="3" class="small muted">No recommended bootstrap mapping in P3.4.0 safe pass.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
