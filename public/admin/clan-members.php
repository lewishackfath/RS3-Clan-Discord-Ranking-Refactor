<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$clanId = (int)env('CLAN_ID', '1');
$clanName = trim((string)env('CLAN_NAME', ''));
$missingTables = require_tables($pdo, ['clan_members']);
$importSummary = $_SESSION['last_clan_import_summary'] ?? null;
unset($_SESSION['last_clan_import_summary']);

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'import_from_api') {
            $summary = import_runescape_clan_members($pdo, $clanId, $clanName);
            $_SESSION['last_clan_import_summary'] = $summary;
            flash('success', sprintf(
                'Imported %d clan members from RuneScape for %s.',
                (int)$summary['fetched'],
                (string)$summary['clan_name']
            ));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/clan-members.php');
}

$members = [];
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
];
if (!$missingTables) {
    $stmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id ORDER BY is_active DESC, rsn ASC');
    $stmt->execute(['clan_id' => $clanId]);
    $members = $stmt->fetchAll() ?: [];

    $stats['total'] = count($members);
    foreach ($members as $member) {
        if ((int)$member['is_active'] === 1) {
            $stats['active']++;
        } else {
            $stats['inactive']++;
        }
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Clan Members</h2>
    <p class="muted">RuneScape is the source of truth for RSN, rank, and active status. This page is read-only apart from importing the latest roster from the RuneScape clan API.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
<div class="card">
    <h3>RuneScape Clan Import</h3>
    <div class="grid two">
        <div>
            <p><strong>Configured Clan Name:</strong> <?= $clanName !== '' ? h($clanName) : '<span class="status bad">Missing CLAN_NAME</span>' ?></p>
            <p class="muted small">Source: members_lite.ws clan roster feed</p>
        </div>
        <div>
            <p><strong>Current local roster:</strong> <?= h((string)$stats['total']) ?> total, <?= h((string)$stats['active']) ?> active, <?= h((string)$stats['inactive']) ?> inactive</p>
        </div>
    </div>

    <?php if ($clanName === ''): ?>
        <div class="flash error">Set <code>CLAN_NAME</code> in your <code>.env</code> before importing from the RuneScape clan API.</div>
    <?php else: ?>
        <form method="post" class="inline">
            <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
            <input type="hidden" name="action" value="import_from_api">
            <button class="btn-primary" type="submit">Import from RuneScape Clan API</button>
        </form>
    <?php endif; ?>

    <?php if (is_array($importSummary)): ?>
        <div style="margin-top:16px">
            <span class="status ok">Last Import Summary</span>
            <table style="margin-top:10px">
                <tbody>
                    <tr><th>Clan</th><td><?= h((string)$importSummary['clan_name']) ?></td></tr>
                    <tr><th>Fetched</th><td><?= h((string)$importSummary['fetched']) ?></td></tr>
                    <tr><th>Inserted</th><td><?= h((string)$importSummary['inserted']) ?></td></tr>
                    <tr><th>Updated</th><td><?= h((string)$importSummary['updated']) ?></td></tr>
                    <tr><th>Reactivated</th><td><?= h((string)$importSummary['reactivated']) ?></td></tr>
                    <tr><th>Inactive after import</th><td><?= h((string)$importSummary['marked_inactive']) ?></td></tr>
                    <tr><th>Active after import</th><td><?= h((string)$importSummary['active_after']) ?></td></tr>
                    <tr><th>Header rows skipped</th><td><?= h((string)$importSummary['header_rows_skipped']) ?></td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Imported Clan Members</h3>
    <p class="muted small">These values are read-only in this admin. Run a fresh import whenever the RuneScape clan roster changes.</p>
    <table>
        <thead>
            <tr>
                <th>RSN</th>
                <th>Normalised</th>
                <th>Rank</th>
                <th>Status</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$members): ?>
            <tr><td colspan="5" class="muted">No clan members have been imported yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($members as $member): ?>
            <tr>
                <td><?= h((string)$member['rsn']) ?></td>
                <td class="small muted mono"><?= h((string)$member['rsn_normalised']) ?></td>
                <td><?= h((string)($member['rank_name'] ?? '')) ?: '<span class="muted">No rank</span>' ?></td>
                <td><span class="status <?= (int)$member['is_active'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$member['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                <td class="small muted"><?= h((string)$member['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
