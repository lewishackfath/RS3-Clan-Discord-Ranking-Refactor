<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$clanId = (int)env('CLAN_ID', '1');
$missingTables = require_tables($pdo, ['clan_members']);

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $rsn = trim((string)($_POST['rsn'] ?? ''));
            $rankName = trim((string)($_POST['rank_name'] ?? ''));
            if ($rsn === '') {
                throw new RuntimeException('RSN is required.');
            }

            $stmt = $pdo->prepare('INSERT INTO clan_members (clan_id, rsn, rsn_normalised, rank_name, is_active) VALUES (:clan_id, :rsn, :rsn_normalised, :rank_name, :is_active)');
            $stmt->execute([
                'clan_id' => $clanId,
                'rsn' => $rsn,
                'rsn_normalised' => normalise_rsn($rsn),
                'rank_name' => $rankName !== '' ? $rankName : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);
            flash('success', 'Clan member added.');
        } elseif ($action === 'save_single') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $rsn = trim((string)($_POST['rsn'] ?? ''));
            $rankName = trim((string)($_POST['rank_name'] ?? ''));
            if ($memberId <= 0 || $rsn === '') {
                throw new RuntimeException('Member ID and RSN are required.');
            }

            $stmt = $pdo->prepare('UPDATE clan_members SET rsn = :rsn, rsn_normalised = :rsn_normalised, rank_name = :rank_name, is_active = :is_active WHERE id = :id AND clan_id = :clan_id');
            $stmt->execute([
                'id' => $memberId,
                'clan_id' => $clanId,
                'rsn' => $rsn,
                'rsn_normalised' => normalise_rsn($rsn),
                'rank_name' => $rankName !== '' ? $rankName : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);
            flash('success', 'Clan member updated.');
        } elseif ($action === 'delete') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) {
                throw new RuntimeException('Invalid member ID.');
            }
            $stmt = $pdo->prepare('DELETE FROM clan_members WHERE id = :id AND clan_id = :clan_id');
            $stmt->execute(['id' => $memberId, 'clan_id' => $clanId]);
            flash('success', 'Clan member deleted.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/clan-members.php');
}

$members = [];
if (!$missingTables) {
    $stmt = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id ORDER BY is_active DESC, rsn ASC');
    $stmt->execute(['clan_id' => $clanId]);
    $members = $stmt->fetchAll() ?: [];
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Clan Members</h2>
    <p class="muted">This page seeds the RuneScape member list used by user mapping and rank mapping previews.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card"><span class="status bad">Setup Required</span><p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p></div>
<?php else: ?>
<div class="card">
    <h3>Add Clan Member</h3>
    <form method="post" class="grid two">
        <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div>
            <label>RSN</label>
            <input type="text" name="rsn" required>
        </div>
        <div>
            <label>Rank Name</label>
            <input type="text" name="rank_name" placeholder="Optional">
        </div>
        <div>
            <label><input type="checkbox" name="is_active" checked> Active member</label>
        </div>
        <div>
            <button class="btn-primary" type="submit">Add Member</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Existing Clan Members</h3>
    <table>
        <thead>
            <tr>
                <th>RSN</th>
                <th>Rank</th>
                <th>Status</th>
                <th>Save</th>
                <th>Delete</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $member): ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_single">
                    <input type="hidden" name="member_id" value="<?= h((string)$member['id']) ?>">
                    <td><input type="text" name="rsn" value="<?= h((string)$member['rsn']) ?>"></td>
                    <td><input type="text" name="rank_name" value="<?= h((string)($member['rank_name'] ?? '')) ?>"></td>
                    <td><label><input type="checkbox" name="is_active" <?= (int)$member['is_active'] === 1 ? 'checked' : '' ?>> Active</label></td>
                    <td><button class="btn-secondary" type="submit">Save</button></td>
                </form>
                <td>
                    <form method="post" onsubmit="return confirm('Delete this clan member?');">
                        <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="member_id" value="<?= h((string)$member['id']) ?>">
                        <button class="btn-secondary" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
