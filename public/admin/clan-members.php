<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$clanId = (int)env('CLAN_ID', '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();

    try {
        $action = (string)($_POST['action'] ?? 'save_single');

        if ($action === 'save_single') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $rsn = trim((string)($_POST['rsn'] ?? ''));
            $rankName = trim((string)($_POST['rank_name'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($rsn === '') {
                throw new RuntimeException('RSN is required.');
            }

            if ($memberId > 0) {
                $stmt = $pdo->prepare('UPDATE clan_members SET rsn = :rsn, rsn_normalised = :normalised, rank_name = :rank_name, is_active = :is_active WHERE id = :id AND clan_id = :clan_id');
                $stmt->execute([
                    'rsn' => $rsn,
                    'normalised' => normalise_rsn($rsn),
                    'rank_name' => $rankName !== '' ? $rankName : null,
                    'is_active' => $isActive,
                    'id' => $memberId,
                    'clan_id' => $clanId,
                ]);
                flash('success', 'Clan member updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO clan_members (clan_id, rsn, rsn_normalised, rank_name, is_active) VALUES (:clan_id, :rsn, :normalised, :rank_name, :is_active)');
                $stmt->execute([
                    'clan_id' => $clanId,
                    'rsn' => $rsn,
                    'normalised' => normalise_rsn($rsn),
                    'rank_name' => $rankName !== '' ? $rankName : null,
                    'is_active' => $isActive,
                ]);
                flash('success', 'Clan member added.');
            }
        }

        if ($action === 'import_csv') {
            $csv = trim((string)($_POST['csv_rows'] ?? ''));
            if ($csv === '') {
                throw new RuntimeException('Paste at least one CSV row.');
            }

            $rows = preg_split('/\r\n|\r|\n/', $csv) ?: [];
            $insert = $pdo->prepare('INSERT INTO clan_members (clan_id, rsn, rsn_normalised, rank_name, is_active)
                VALUES (:clan_id, :rsn, :normalised, :rank_name, 1)
                ON DUPLICATE KEY UPDATE rsn = VALUES(rsn), rank_name = VALUES(rank_name), is_active = 1');
            $count = 0;
            foreach ($rows as $row) {
                $row = trim($row);
                if ($row === '') {
                    continue;
                }
                $parts = str_getcsv($row);
                $rsn = trim((string)($parts[0] ?? ''));
                $rankName = trim((string)($parts[1] ?? ''));
                if ($rsn === '' || strcasecmp($rsn, 'rsn') === 0) {
                    continue;
                }
                $insert->execute([
                    'clan_id' => $clanId,
                    'rsn' => $rsn,
                    'normalised' => normalise_rsn($rsn),
                    'rank_name' => $rankName !== '' ? $rankName : null,
                ]);
                $count++;
            }
            flash('success', 'Imported ' . $count . ' clan member row(s).');
        }

        if ($action === 'delete') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM clan_members WHERE id = :id AND clan_id = :clan_id');
            $stmt->execute(['id' => $memberId, 'clan_id' => $clanId]);
            flash('success', 'Clan member deleted.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/clan-members.php');
}

$members = $pdo->prepare('SELECT * FROM clan_members WHERE clan_id = :clan_id ORDER BY is_active DESC, rsn ASC');
$members->execute(['clan_id' => $clanId]);
$members = $members->fetchAll();

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="grid two">
    <div class="card">
        <h2>Add Clan Member</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
            <input type="hidden" name="action" value="save_single">
            <input type="hidden" name="member_id" value="0">
            <p><label>RSN<br><input type="text" name="rsn" required></label></p>
            <p><label>Rank Name<br><input type="text" name="rank_name"></label></p>
            <p><label><input type="checkbox" name="is_active" checked> Active</label></p>
            <p><button class="btn-primary" type="submit">Add Member</button></p>
        </form>
    </div>
    <div class="card">
        <h2>Bulk Import</h2>
        <p class="muted">Paste <code>RSN,Rank</code> rows. Header row is optional.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">
            <input type="hidden" name="action" value="import_csv">
            <p><textarea name="csv_rows" placeholder="RSN,Rank&#10;Example User,Captain&#10;Another User,Recruit"></textarea></p>
            <p><button class="btn-primary" type="submit">Import Rows</button></p>
        </form>
    </div>
</div>

<div class="card">
    <h2>Current Clan Members</h2>
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
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
