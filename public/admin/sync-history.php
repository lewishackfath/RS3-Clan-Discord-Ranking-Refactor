<?php

declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$clanId = (int)env('CLAN_ID', '1');
$guildId = (string)env('DISCORD_GUILD_ID', '');
$missingTables = require_tables($pdo, ['sync_runs', 'sync_run_members']);

function sync_run_status_meta(string $status): array
{
    return match ($status) {
        'completed' => ['Completed', 'ok'],
        'completed_with_errors' => ['Completed with Errors', 'warn'],
        'running' => ['Running', 'warn'],
        'failed' => ['Failed', 'bad'],
        default => [ucwords(str_replace('_', ' ', $status)), 'warn'],
    };
}

function sync_member_status_meta(string $status): array
{
    return match ($status) {
        'changed' => ['Changed', 'warn'],
        'no_change' => ['No Change', 'ok'],
        'blocked_hierarchy' => ['Blocked', 'bad'],
        'ambiguous_match' => ['Ambiguous Match', 'bad'],
        'no_rank_mapping' => ['No Rank Mapping', 'warn'],
        'error' => ['Error', 'bad'],
        'skipped' => ['Skipped', 'warn'],
        default => [ucwords(str_replace('_', ' ', $status)), 'warn'],
    };
}

function role_ids_to_badges(?string $csv, array $roleMap): string
{
    $ids = csv_ids((string)$csv);
    if ($ids === []) {
        return '<span class="muted small">—</span>';
    }

    $html = '<div class="role-chip-wrap">';
    foreach ($ids as $id) {
        $role = $roleMap[$id] ?? null;
        $label = $role ? (string)($role['name'] ?? $id) : $id;
        $html .= '<span class="role-chip mono">' . h($label) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

$runStatusFilter = trim((string)($_GET['run_status'] ?? 'all'));
$allowedRunStatus = ['all', 'running', 'completed', 'completed_with_errors', 'failed'];
if (!in_array($runStatusFilter, $allowedRunStatus, true)) {
    $runStatusFilter = 'all';
}

$selectedRunId = max(0, (int)($_GET['run_id'] ?? 0));
$memberStatusFilter = trim((string)($_GET['member_status'] ?? 'all'));
$allowedMemberStatus = ['all', 'changed', 'no_change', 'blocked_hierarchy', 'ambiguous_match', 'no_rank_mapping', 'error', 'skipped'];
if (!in_array($memberStatusFilter, $allowedMemberStatus, true)) {
    $memberStatusFilter = 'all';
}

$search = trim((string)($_GET['search'] ?? ''));
$onlyGuests = isset($_GET['only_guests']) && $_GET['only_guests'] === '1';
$onlyErrors = isset($_GET['only_errors']) && $_GET['only_errors'] === '1';
$onlyChanged = isset($_GET['only_changed']) && $_GET['only_changed'] === '1';

$runs = [];
$selectedRun = null;
$runSummary = null;
$memberRows = [];
$roleMap = [];
$errorMessage = null;

if (!$missingTables) {
    try {
        if ($guildId !== '') {
            $roleMap = discord_role_map(discord_get_guild_roles($guildId));
        }
    } catch (Throwable $e) {
        $errorMessage = 'Role names could not be loaded from Discord. Showing raw role IDs instead. ' . $e->getMessage();
    }

    $runSql = 'SELECT * FROM sync_runs WHERE clan_id = :clan_id';
    $runParams = ['clan_id' => $clanId];
    if ($runStatusFilter !== 'all') {
        $runSql .= ' AND status = :run_status';
        $runParams['run_status'] = $runStatusFilter;
    }
    $runSql .= ' ORDER BY started_at_utc DESC, id DESC LIMIT 50';
    $runStmt = $pdo->prepare($runSql);
    $runStmt->execute($runParams);
    $runs = $runStmt->fetchAll() ?: [];

    if ($selectedRunId <= 0 && $runs) {
        $selectedRunId = (int)$runs[0]['id'];
    }

    if ($selectedRunId > 0) {
        $selectedStmt = $pdo->prepare('SELECT * FROM sync_runs WHERE clan_id = :clan_id AND id = :id LIMIT 1');
        $selectedStmt->execute(['clan_id' => $clanId, 'id' => $selectedRunId]);
        $selectedRun = $selectedStmt->fetch() ?: null;
    }

    if ($selectedRun) {
        $summaryStmt = $pdo->prepare('SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN status = "changed" THEN 1 ELSE 0 END) AS changed_rows,
                SUM(CASE WHEN status = "no_change" THEN 1 ELSE 0 END) AS unchanged_rows,
                SUM(CASE WHEN status = "blocked_hierarchy" THEN 1 ELSE 0 END) AS blocked_rows,
                SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) AS error_rows,
                SUM(CASE WHEN resolved_rsn IS NULL OR resolved_rsn = "" THEN 1 ELSE 0 END) AS guest_rows,
                SUM(CASE WHEN guest_dm_attempted = 1 THEN 1 ELSE 0 END) AS guest_dm_attempted_rows,
                SUM(CASE WHEN guest_dm_success = 1 THEN 1 ELSE 0 END) AS guest_dm_success_rows
            FROM sync_run_members
            WHERE sync_run_id = :sync_run_id');
        $summaryStmt->execute(['sync_run_id' => (int)$selectedRun['id']]);
        $runSummary = $summaryStmt->fetch() ?: null;

        $memberSql = 'SELECT * FROM sync_run_members WHERE sync_run_id = :sync_run_id';
        $memberParams = ['sync_run_id' => (int)$selectedRun['id']];

        if ($memberStatusFilter !== 'all') {
            $memberSql .= ' AND status = :member_status';
            $memberParams['member_status'] = $memberStatusFilter;
        }
        if ($onlyGuests) {
            $memberSql .= ' AND (resolved_rsn IS NULL OR resolved_rsn = "")';
        }
        if ($onlyErrors) {
            $memberSql .= ' AND (status = "error" OR guest_dm_success = 0 AND guest_dm_attempted = 1 OR guest_dm_error IS NOT NULL AND guest_dm_error <> "")';
        }
        if ($onlyChanged) {
            $memberSql .= ' AND status = "changed"';
        }
        if ($search !== '') {
            $memberSql .= ' AND (
                discord_username LIKE :search
                OR discord_display_name LIKE :search
                OR resolved_rsn LIKE :search
                OR resolved_rank_name LIKE :search
                OR notes LIKE :search
            )';
            $memberParams['search'] = '%' . $search . '%';
        }

        $memberSql .= ' ORDER BY
            CASE status
                WHEN "error" THEN 0
                WHEN "blocked_hierarchy" THEN 1
                WHEN "changed" THEN 2
                WHEN "ambiguous_match" THEN 3
                WHEN "no_rank_mapping" THEN 4
                WHEN "no_change" THEN 5
                ELSE 6
            END,
            discord_display_name ASC,
            discord_username ASC
            LIMIT 250';

        $memberStmt = $pdo->prepare($memberSql);
        $memberStmt->execute($memberParams);
        $memberRows = $memberStmt->fetchAll() ?: [];
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <div class="table-actions">
        <div>
            <h2 style="margin:0 0 8px;">Sync History</h2>
            <div class="muted">Review previous manual sync runs, member-level actions, Guest fallbacks, and Discord role changes.</div>
        </div>
        <div class="inline">
            <a class="btn-secondary" href="/admin/sync-preview.php">Back to Sync Preview</a>
        </div>
    </div>
</div>

<?php if ($missingTables): ?>
    <div class="card">
        <span class="status bad">Setup Required</span>
        <p>The sync audit tables are missing. Run <code>sql/migrations/phase3.0-manual-apply-sync-audit-log.sql</code> first.</p>
    </div>
<?php else: ?>
    <?php if ($errorMessage): ?>
        <div class="flash info"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="get" class="toolbar">
            <div>
                <label class="small muted" for="run_status">Run Status</label>
                <select id="run_status" name="run_status">
                    <option value="all" <?= $runStatusFilter === 'all' ? 'selected' : '' ?>>All Runs</option>
                    <option value="completed" <?= $runStatusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="completed_with_errors" <?= $runStatusFilter === 'completed_with_errors' ? 'selected' : '' ?>>Completed with Errors</option>
                    <option value="running" <?= $runStatusFilter === 'running' ? 'selected' : '' ?>>Running</option>
                    <option value="failed" <?= $runStatusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <?php if ($selectedRunId > 0): ?>
                <input type="hidden" name="run_id" value="<?= h((string)$selectedRunId) ?>">
            <?php endif; ?>
            <div>
                <button class="btn-primary" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <div class="grid two">
        <div class="card">
            <h3>Recent Runs</h3>
            <?php if (!$runs): ?>
                <p class="muted">No sync runs have been recorded yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Run</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th>Changed</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($runs as $run): ?>
                        <?php [$runLabel, $runClass] = sync_run_status_meta((string)$run['status']); ?>
                        <tr>
                            <td>
                                <div class="stack">
                                    <a href="?<?= h(http_build_query(array_merge($_GET, ['run_id' => (int)$run['id']])) ) ?>"><strong>#<?= h((string)$run['id']) ?></strong></a>
                                    <span class="muted small">By <?= h((string)($run['initiated_by_name'] ?: 'Unknown')) ?></span>
                                </div>
                            </td>
                            <td class="nowrap">
                                <div class="stack">
                                    <span><?= h((string)$run['started_at_utc']) ?> UTC</span>
                                    <span class="muted small"><?= !empty($run['finished_at_utc']) ? h((string)$run['finished_at_utc']) . ' UTC' : 'In progress' ?></span>
                                </div>
                            </td>
                            <td><span class="status <?= h($runClass) ?>"><?= h($runLabel) ?></span></td>
                            <td><?= h((string)($run['changed_members'] ?? 0)) ?> / <?= h((string)($run['total_members'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Selected Run</h3>
            <?php if (!$selectedRun): ?>
                <p class="muted">Choose a run from the left to inspect it.</p>
            <?php else: ?>
                <?php [$runLabel, $runClass] = sync_run_status_meta((string)$selectedRun['status']); ?>
                <table>
                    <tr><th>Run ID</th><td>#<?= h((string)$selectedRun['id']) ?></td></tr>
                    <tr><th>Status</th><td><span class="status <?= h($runClass) ?>"><?= h($runLabel) ?></span></td></tr>
                    <tr><th>Started</th><td><?= h((string)$selectedRun['started_at_utc']) ?> UTC</td></tr>
                    <tr><th>Finished</th><td><?= !empty($selectedRun['finished_at_utc']) ? h((string)$selectedRun['finished_at_utc']) . ' UTC' : '<span class="muted">Still running</span>' ?></td></tr>
                    <tr><th>Initiated By</th><td><?= h((string)($selectedRun['initiated_by_name'] ?: 'Unknown')) ?></td></tr>
                    <tr><th>Summary</th><td><?= !empty($selectedRun['summary_text']) ? h((string)$selectedRun['summary_text']) : '<span class="muted">No summary recorded</span>' ?></td></tr>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedRun && $runSummary): ?>
        <div class="card">
            <div class="preview-grid">
                <div class="stat"><div class="muted small">Processed</div><div class="value"><?= h((string)($runSummary['total_rows'] ?? 0)) ?></div></div>
                <div class="stat"><div class="muted small">Changed</div><div class="value"><?= h((string)($runSummary['changed_rows'] ?? 0)) ?></div></div>
                <div class="stat"><div class="muted small">No Change</div><div class="value"><?= h((string)($runSummary['unchanged_rows'] ?? 0)) ?></div></div>
                <div class="stat"><div class="muted small">Guests</div><div class="value"><?= h((string)($runSummary['guest_rows'] ?? 0)) ?></div></div>
                <div class="stat"><div class="muted small">Errors / Blocked</div><div class="value"><?= h((string)(((int)($runSummary['error_rows'] ?? 0)) + ((int)($runSummary['blocked_rows'] ?? 0)))) ?></div></div>
            </div>
            <div class="table-actions" style="margin-top:18px;">
                <div class="hint">Guest DMs attempted: <?= h((string)($runSummary['guest_dm_attempted_rows'] ?? 0)) ?> • successful: <?= h((string)($runSummary['guest_dm_success_rows'] ?? 0)) ?></div>
            </div>
        </div>

        <div class="card">
            <form method="get" class="toolbar">
                <input type="hidden" name="run_id" value="<?= h((string)$selectedRun['id']) ?>">
                <input type="hidden" name="run_status" value="<?= h($runStatusFilter) ?>">
                <div class="grow">
                    <label class="small muted" for="search">Search</label>
                    <input id="search" type="text" name="search" value="<?= h($search) ?>" placeholder="Discord name, RSN, rank, note...">
                </div>
                <div>
                    <label class="small muted" for="member_status">Member Status</label>
                    <select id="member_status" name="member_status">
                        <option value="all" <?= $memberStatusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="changed" <?= $memberStatusFilter === 'changed' ? 'selected' : '' ?>>Changed</option>
                        <option value="no_change" <?= $memberStatusFilter === 'no_change' ? 'selected' : '' ?>>No Change</option>
                        <option value="blocked_hierarchy" <?= $memberStatusFilter === 'blocked_hierarchy' ? 'selected' : '' ?>>Blocked</option>
                        <option value="ambiguous_match" <?= $memberStatusFilter === 'ambiguous_match' ? 'selected' : '' ?>>Ambiguous Match</option>
                        <option value="no_rank_mapping" <?= $memberStatusFilter === 'no_rank_mapping' ? 'selected' : '' ?>>No Rank Mapping</option>
                        <option value="error" <?= $memberStatusFilter === 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="skipped" <?= $memberStatusFilter === 'skipped' ? 'selected' : '' ?>>Skipped</option>
                    </select>
                </div>
                <div>
                    <label class="small muted"><input type="checkbox" name="only_changed" value="1" <?= $onlyChanged ? 'checked' : '' ?>> Changed only</label><br>
                    <label class="small muted"><input type="checkbox" name="only_errors" value="1" <?= $onlyErrors ? 'checked' : '' ?>> Errors only</label><br>
                    <label class="small muted"><input type="checkbox" name="only_guests" value="1" <?= $onlyGuests ? 'checked' : '' ?>> Guests only</label>
                </div>
                <div>
                    <button class="btn-primary" type="submit">Filter</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="table-actions">
                <h3 style="margin:0;">Run Members</h3>
                <div class="hint">Showing up to 250 rows for this run.</div>
            </div>
            <?php if (!$memberRows): ?>
                <p class="muted">No members matched the current filters.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Discord User</th>
                        <th>Resolved Member</th>
                        <th>Status</th>
                        <th>Roles Added</th>
                        <th>Roles Removed</th>
                        <th>Blocked</th>
                        <th>Guest DM</th>
                        <th>Notes</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($memberRows as $row): ?>
                        <?php [$label, $class] = sync_member_status_meta((string)$row['status']); ?>
                        <tr>
                            <td>
                                <div class="stack">
                                    <strong><?= h((string)($row['discord_display_name'] ?: $row['discord_username'])) ?></strong>
                                    <span class="muted small mono">@<?= h((string)$row['discord_username']) ?></span>
                                    <span class="muted small mono"><?= h((string)$row['discord_user_id']) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="stack">
                                    <strong><?= !empty($row['resolved_rsn']) ? h((string)$row['resolved_rsn']) : 'Guest fallback' ?></strong>
                                    <span class="muted small"><?= !empty($row['resolved_rank_name']) ? h((string)$row['resolved_rank_name']) : 'No rank resolved' ?></span>
                                    <span class="muted small">Resolved by: <?= h((string)($row['resolved_by'] ?: 'none')) ?></span>
                                </div>
                            </td>
                            <td><span class="status <?= h($class) ?>"><?= h($label) ?></span></td>
                            <td><?= role_ids_to_badges((string)($row['added_role_ids_csv'] ?? ''), $roleMap) ?></td>
                            <td><?= role_ids_to_badges((string)($row['removed_role_ids_csv'] ?? ''), $roleMap) ?></td>
                            <td><?= role_ids_to_badges((string)($row['blocked_role_ids_csv'] ?? ''), $roleMap) ?></td>
                            <td>
                                <div class="stack small">
                                    <span><?= !empty($row['guest_dm_attempted']) ? 'Attempted' : '—' ?></span>
                                    <span class="<?= !empty($row['guest_dm_success']) ? 'status ok' : 'muted' ?>" style="display:inline-block;"><?= !empty($row['guest_dm_success']) ? 'Sent' : (!empty($row['guest_dm_attempted']) ? 'Failed' : 'Not needed') ?></span>
                                    <?php if (!empty($row['guest_dm_error'])): ?>
                                        <span class="muted"><?= h((string)$row['guest_dm_error']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($row['notes'])): ?>
                                    <div class="small"><?= nl2br(h((string)$row['notes'])) ?></div>
                                <?php else: ?>
                                    <span class="muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
