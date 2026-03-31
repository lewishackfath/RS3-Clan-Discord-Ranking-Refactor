<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['rs_rank_mappings', 'discord_role_flags']);
$singleSelectRanks = ['Guest', 'Clan Member'];

function parse_new_role_names(string $value): array
{
    $parts = preg_split('/[
,]+/', $value) ?: [];
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
        $singleSelectedRoles = is_array($_POST['discord_role_id_single'] ?? null) ? $_POST['discord_role_id_single'] : [];
        $newRoleNames = is_array($_POST['new_role_names'] ?? null) ? $_POST['new_role_names'] : [];
        $enabledRows = is_array($_POST['is_enabled'] ?? null) ? $_POST['is_enabled'] : [];

        $deleteStmt = $pdo->prepare('DELETE FROM rs_rank_mappings WHERE clan_id = :clan_id AND rs_rank_name = :rank_name');
        $insertStmt = $pdo->prepare('INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
            VALUES (:clan_id, :rank_name, :role_id, :role_name, :is_enabled)');

        foreach (rs_rank_order() as $rankName) {
            if (in_array($rankName, $singleSelectRanks, true)) {
                $selectedRoleId = trim((string)($singleSelectedRoles[$rankName] ?? ''));
                $existingRoleIds = $selectedRoleId !== '' ? [$selectedRoleId] : [];
            } else {
                $existingRoleIds = $selectedRoles[$rankName] ?? [];
                if (!is_array($existingRoleIds)) {
                    $existingRoleIds = [$existingRoleIds];
                }
                $existingRoleIds = array_values(array_unique(array_filter(array_map('strval', $existingRoleIds), static fn(string $id): bool => trim($id) !== '')));
            }

            $createNames = parse_new_role_names((string)($newRoleNames[$rankName] ?? ''));
            $isEnabled = isset($enabledRows[$rankName]) ? 1 : 0;

            foreach ($createNames as $newRoleName) {
                $created = discord_create_role($guildId, $newRoleName);
                $roleId = (string)$created['id'];
                $roleMap[$roleId] = $created;
                $existingRoleIds[] = $roleId;
            }

            $existingRoleIds = array_values(array_unique($existingRoleIds));
            if (in_array($rankName, $singleSelectRanks, true) && count($existingRoleIds) > 1) {
                $existingRoleIds = [reset($existingRoleIds) ?: ''];
                $existingRoleIds = array_values(array_filter($existingRoleIds, static fn(string $id): bool => trim($id) !== ''));
            }

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
    <p class="muted">Mapped clan ranks can now use a cleaner checkbox dropdown for multi-role selection. Guest and Clan Member stay single-select because they are always one-to-one.</p>
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
        <style>
            .role-picker { position: relative; min-width: 300px; }
            .role-picker-toggle {
                width: 100%; text-align: left; background: #0b1220; color: var(--text);
                border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; cursor: pointer;
            }
            .role-picker-toggle .summary { display:block; white-space: nowrap; overflow:hidden; text-overflow: ellipsis; }
            .role-picker-menu {
                display:none; position:absolute; top: calc(100% + 6px); left:0; right:0; z-index:30;
                background: #0b1220; border:1px solid var(--line); border-radius: 12px; padding: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.35);
                max-height: 320px; overflow: auto;
            }
            .role-picker.open .role-picker-menu { display:block; }
            .role-picker-search { margin-bottom: 8px; }
            .role-picker-option { display:flex; gap:8px; align-items:flex-start; padding: 6px 4px; border-radius: 8px; }
            .role-picker-option:hover { background: rgba(255,255,255,.04); }
            .role-picker-option input { margin-top: 2px; }
            .role-picker-empty { padding: 8px 4px; color: var(--muted); }
        </style>
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
                $isSingle = in_array($rankName, $singleSelectRanks, true);
                $currentSummary = array_filter($row['discord_role_names'] ?? []);
            ?>
                <tr>
                    <td>
                        <strong><?= h($rankName) ?></strong>
                        <?php if ($currentSummary): ?>
                            <br><span class="small muted">Current: <?= h(implode(', ', $currentSummary)) ?></span>
                        <?php endif; ?>
                        <?php if ($isSingle): ?>
                            <br><span class="small muted">Single-role mapping</span>
                        <?php endif; ?>
                        <?php if (!empty($roleWarnings[$rankName])): ?>
                            <?php foreach ($roleWarnings[$rankName] as $warning): ?>
                                <br><span class="small" style="color:#fdba74"><?= h($warning) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isSingle): ?>
                            <select name="discord_role_id_single[<?= h($rankName) ?>]">
                                <option value="">— No role selected —</option>
                                <?php foreach ($discordRoles as $role): ?>
                                    <?php if ((string)$role['name'] === '@everyone') continue; ?>
                                    <option value="<?= h((string)$role['id']) ?>" <?= in_array((string)$role['id'], $selected, true) ? 'selected' : '' ?>>
                                        <?= h((string)$role['name']) ?> (position <?= h((string)$role['position']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <div class="role-picker" data-role-picker>
                                <button class="role-picker-toggle" type="button" data-role-picker-toggle>
                                    <span class="summary" data-role-picker-summary><?= $currentSummary ? h(implode(', ', $currentSummary)) : 'Select one or more Discord roles' ?></span>
                                </button>
                                <div class="role-picker-menu">
                                    <input class="role-picker-search" type="text" placeholder="Search roles..." data-role-picker-search>
                                    <div data-role-picker-options>
                                        <?php foreach ($discordRoles as $role): ?>
                                            <?php if ((string)$role['name'] === '@everyone') continue; ?>
                                            <label class="role-picker-option" data-role-picker-option data-filter-text="<?= h(mb_strtolower((string)$role['name'] . ' ' . (string)$role['position'], 'UTF-8')) ?>">
                                                <input type="checkbox" name="discord_role_ids[<?= h($rankName) ?>][]" value="<?= h((string)$role['id']) ?>" data-role-picker-checkbox data-role-name="<?= h((string)$role['name']) ?>" <?= in_array((string)$role['id'], $selected, true) ? 'checked' : '' ?>>
                                                <span><?= h((string)$role['name']) ?> <span class="small muted">(position <?= h((string)$role['position']) ?>)</span></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="role-picker-empty" data-role-picker-empty hidden>No roles match that search.</div>
                                </div>
                            </div>
                            <div class="small muted" style="margin-top:6px">Click to open, then tick the roles you want.</div>
                        <?php endif; ?>
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
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const pickers = document.querySelectorAll('[data-role-picker]');
        const updateSummary = (picker) => {
            const checked = Array.from(picker.querySelectorAll('[data-role-picker-checkbox]:checked')).map(el => el.getAttribute('data-role-name') || '').filter(Boolean);
            const summary = picker.querySelector('[data-role-picker-summary]');
            summary.textContent = checked.length ? checked.join(', ') : 'Select one or more Discord roles';
        };

        pickers.forEach((picker) => {
            const toggle = picker.querySelector('[data-role-picker-toggle]');
            const search = picker.querySelector('[data-role-picker-search]');
            const options = Array.from(picker.querySelectorAll('[data-role-picker-option]'));
            const empty = picker.querySelector('[data-role-picker-empty]');

            updateSummary(picker);

            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                document.querySelectorAll('[data-role-picker].open').forEach((openPicker) => {
                    if (openPicker !== picker) {
                        openPicker.classList.remove('open');
                    }
                });
                picker.classList.toggle('open');
                if (picker.classList.contains('open')) {
                    search.focus();
                }
            });

            picker.querySelectorAll('[data-role-picker-checkbox]').forEach((checkbox) => {
                checkbox.addEventListener('change', function () { updateSummary(picker); });
            });

            search.addEventListener('input', function () {
                const needle = search.value.trim().toLowerCase();
                let visibleCount = 0;
                options.forEach((option) => {
                    const haystack = (option.getAttribute('data-filter-text') || '').toLowerCase();
                    const visible = needle === '' || haystack.includes(needle);
                    option.hidden = !visible;
                    if (visible) visibleCount++;
                });
                empty.hidden = visibleCount !== 0;
            });
        });

        document.addEventListener('click', function (event) {
            document.querySelectorAll('[data-role-picker].open').forEach((picker) => {
                if (!picker.contains(event.target)) {
                    picker.classList.remove('open');
                }
            });
        });
    });
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
