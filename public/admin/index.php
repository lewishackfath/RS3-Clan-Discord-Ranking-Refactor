<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$summary = null;
$error = null;

try {
    $response = bot_service_request('/guild/summary');
    if (($response['status'] ?? 500) !== 200 || !is_array($response['json'])) {
        throw new RuntimeException('Bot service did not return a valid guild summary.');
    }
    $summary = $response['json'];

    $botHighestPosition = (int)($summary['bot']['highest_role']['position'] ?? -1);
    $problemRoles = [];

    $stmt = db()->prepare('SELECT discord_role_id, discord_role_name_cache FROM rs_rank_mappings WHERE clan_id = ? AND discord_role_id IS NOT NULL');
    $stmt->execute([(int)env('CLAN_ID', '1')]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (($summary['roles'] ?? []) as $role) {
            if ((string)$role['id'] === (string)$row['discord_role_id'] && (int)$role['position'] >= $botHighestPosition) {
                $problemRoles[] = $role['name'];
            }
        }
    }

    if ($problemRoles) {
        $msg = 'Bot hierarchy invalid. Move the bot role above: ' . implode(', ', array_unique($problemRoles));
        $upsert = db()->prepare('INSERT INTO guild_settings (clan_id, discord_guild_id, guild_name_cache, bot_user_id, bot_role_id, bot_role_name_cache, last_validation_at, validation_status, validation_message)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE guild_name_cache = VALUES(guild_name_cache), bot_user_id = VALUES(bot_user_id), bot_role_id = VALUES(bot_role_id), bot_role_name_cache = VALUES(bot_role_name_cache), last_validation_at = NOW(), validation_status = VALUES(validation_status), validation_message = VALUES(validation_message)');
        $upsert->execute([
            (int)env('CLAN_ID', '1'),
            (string)($summary['guild']['id'] ?? env('DISCORD_GUILD_ID', '')),
            (string)($summary['guild']['name'] ?? ''),
            (string)($summary['bot']['user_id'] ?? ''),
            (string)($summary['bot']['highest_role']['id'] ?? ''),
            (string)($summary['bot']['highest_role']['name'] ?? ''),
            'error',
            $msg,
        ]);
    } else {
        $upsert = db()->prepare('INSERT INTO guild_settings (clan_id, discord_guild_id, guild_name_cache, bot_user_id, bot_role_id, bot_role_name_cache, last_validation_at, validation_status, validation_message)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE guild_name_cache = VALUES(guild_name_cache), bot_user_id = VALUES(bot_user_id), bot_role_id = VALUES(bot_role_id), bot_role_name_cache = VALUES(bot_role_name_cache), last_validation_at = NOW(), validation_status = VALUES(validation_status), validation_message = VALUES(validation_message)');
        $upsert->execute([
            (int)env('CLAN_ID', '1'),
            (string)($summary['guild']['id'] ?? env('DISCORD_GUILD_ID', '')),
            (string)($summary['guild']['name'] ?? ''),
            (string)($summary['bot']['user_id'] ?? ''),
            (string)($summary['bot']['highest_role']['id'] ?? ''),
            (string)($summary['bot']['highest_role']['name'] ?? ''),
            'ok',
            'Bot hierarchy check passed.',
        ]);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$settings = db()->prepare('SELECT * FROM guild_settings WHERE clan_id = ? LIMIT 1');
$settings->execute([(int)env('CLAN_ID', '1')]);
$guildSettings = $settings->fetch() ?: null;

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Dashboard</h2>
    <p class="muted">This page checks bot readiness and Discord guild hierarchy before role management is enabled.</p>
</div>

<?php if ($error): ?>
    <div class="card">
        <h3>Bot Service Error</h3>
        <p><?= h($error) ?></p>
    </div>
<?php elseif ($summary): ?>
    <div class="grid-2">
        <div class="card">
            <h3>Guild Status</h3>
            <p><strong>Guild:</strong> <?= h($summary['guild']['name'] ?? '') ?></p>
            <p><strong>Guild ID:</strong> <?= h($summary['guild']['id'] ?? '') ?></p>
            <p><strong>Members:</strong> <?= h((string)($summary['guild']['member_count'] ?? '0')) ?></p>
        </div>
        <div class="card">
            <h3>Bot Status</h3>
            <p><strong>Bot:</strong> <?= h($summary['bot']['username'] ?? '') ?></p>
            <p><strong>Highest Role:</strong> <?= h($summary['bot']['highest_role']['name'] ?? 'Unknown') ?></p>
            <p><strong>Role Position:</strong> <?= h((string)($summary['bot']['highest_role']['position'] ?? '')) ?></p>
        </div>
    </div>

    <div class="card">
        <h3>Validation Result</h3>
        <?php $status = (string)($guildSettings['validation_status'] ?? 'unknown'); ?>
        <p>
            <?php if ($status === 'ok'): ?>
                <span class="badge ok">Ready</span>
            <?php elseif ($status === 'error'): ?>
                <span class="badge bad">Action Required</span>
            <?php else: ?>
                <span class="badge warn">Unknown</span>
            <?php endif; ?>
        </p>
        <p><?= h($guildSettings['validation_message'] ?? 'No validation result recorded.') ?></p>
        <p class="small muted">If the bot role is not above the roles it must manage, Discord will reject role assignments even if the bot has broad permissions.</p>
    </div>

    <div class="card">
        <h3>Current Discord Roles</h3>
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Position</th>
                    <th>Managed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (($summary['roles'] ?? []) as $role): ?>
                <tr>
                    <td><?= h($role['name']) ?></td>
                    <td><?= h((string)$role['position']) ?></td>
                    <td><?= !empty($role['managed']) ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php';
