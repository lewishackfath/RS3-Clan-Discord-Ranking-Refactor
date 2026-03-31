<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$pdo = db();
$requiredTables = ['guild_settings', 'rs_rank_mappings', 'discord_role_flags', 'discord_user_mappings', 'clan_members'];
$missingTables = require_tables($pdo, $requiredTables);
$settingsMissingColumns = !$missingTables ? require_columns($pdo, 'guild_settings', [
    'log_channel_id',
    'log_channel_name_cache',
    'send_guest_dm',
    'guest_dm_message',
    'auto_sync_enabled',
    'auto_sync_interval_minutes',
    'last_auto_sync_at',
]) : [];

$status = null;
$errorMessage = null;
$mappedRoleIds = [];
$botRoleIds = [];
$discordSettings = null;
$latestSyncRun = null;
$hasTriggerSource = table_exists($pdo, 'sync_runs') && column_exists($pdo, 'sync_runs', 'trigger_source');

if (!$missingTables) {
    if (!$settingsMissingColumns) {
        $settingsStmt = $pdo->prepare('SELECT * FROM guild_settings WHERE clan_id = :clan_id LIMIT 1');
        $settingsStmt->execute(['clan_id' => $clanId]);
        $discordSettings = $settingsStmt->fetch() ?: null;
    }

    $mappedStmt = $pdo->prepare('SELECT discord_role_id FROM rs_rank_mappings WHERE clan_id = :clan_id AND discord_role_id IS NOT NULL AND discord_role_id <> ""');
    $mappedStmt->execute(['clan_id' => $clanId]);
    $mappedRoleIds = array_map('strval', $mappedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $botStmt = $pdo->prepare('SELECT discord_role_id FROM discord_role_flags WHERE discord_guild_id = :guild_id AND is_bot_role = 1');
    $botStmt->execute(['guild_id' => $guildId]);
    $botRoleIds = array_map('strval', $botStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    if (table_exists($pdo, 'sync_runs')) {
        $latestRunStmt = $pdo->prepare('SELECT * FROM sync_runs WHERE clan_id = :clan_id ORDER BY started_at_utc DESC, id DESC LIMIT 1');
        $latestRunStmt->execute(['clan_id' => $clanId]);
        $latestSyncRun = $latestRunStmt->fetch() ?: null;
    }

    try {
        $status = validate_bot_readiness($guildId, $mappedRoleIds, $botRoleIds);

        $stmt = $pdo->prepare('INSERT INTO guild_settings (clan_id, discord_guild_id, guild_name_cache, bot_user_id, bot_role_id, bot_role_name_cache, last_validation_at, validation_status, validation_message)
            VALUES (:clan_id, :guild_id, :guild_name, :bot_user_id, :bot_role_id, :bot_role_name, :validated_at, :status, :message)
            ON DUPLICATE KEY UPDATE guild_name_cache = VALUES(guild_name_cache), bot_user_id = VALUES(bot_user_id), bot_role_id = VALUES(bot_role_id), bot_role_name_cache = VALUES(bot_role_name_cache), last_validation_at = VALUES(last_validation_at), validation_status = VALUES(validation_status), validation_message = VALUES(validation_message)');

        $botHighestRoleId = $status['bot_highest_role']['id'] ?? null;
        $stmt->execute([
            'clan_id' => $clanId,
            'guild_id' => $guildId,
            'guild_name' => (string)($status['guild']['name'] ?? ''),
            'bot_user_id' => (string)($status['bot_user']['id'] ?? ''),
            'bot_role_id' => $botHighestRoleId,
            'bot_role_name' => $botHighestRoleId ? (string)($status['role_map'][$botHighestRoleId]['name'] ?? '') : null,
            'validated_at' => now_utc(),
            'status' => $status['ok'] ? 'ok' : 'blocked',
            'message' => implode(' ', $status['messages']),
        ]);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$nextEligibleAutoSync = null;
if ($discordSettings && !empty($discordSettings['last_auto_sync_at']) && !empty($discordSettings['auto_sync_interval_minutes'])) {
    $nextEligibleAutoSync = (new DateTimeImmutable((string)$discordSettings['last_auto_sync_at'], new DateTimeZone('UTC')))
        ->modify('+' . max(1, (int)$discordSettings['auto_sync_interval_minutes']) . ' minutes');
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Server Readiness</h2>
    <p class="muted">This checks the bot installation, guild access and role hierarchy directly through Discord's REST API using the bot token in your <code>.env</code>.</p>
</div>

<?php if (!$missingTables && !$settingsMissingColumns): ?>
<div class="grid two">
    <div class="card">
        <h3>Discord Settings</h3>
        <table>
            <tr><th>Log Channel</th><td><?= !empty($discordSettings['log_channel_name_cache']) ? '#' . h((string)$discordSettings['log_channel_name_cache']) : '<span class="muted">Not configured</span>' ?></td></tr>
            <tr><th>Guest DM</th><td><span class="status <?= !empty($discordSettings['send_guest_dm']) ? 'ok' : 'warn' ?>"><?= !empty($discordSettings['send_guest_dm']) ? 'Enabled' : 'Disabled' ?></span></td></tr>
            <tr><th>Auto Sync</th><td><span class="status <?= !empty($discordSettings['auto_sync_enabled']) ? 'ok' : 'warn' ?>"><?= !empty($discordSettings['auto_sync_enabled']) ? 'Enabled' : 'Disabled' ?></span></td></tr>
            <tr><th>Frequency</th><td><?= h((string)($discordSettings['auto_sync_interval_minutes'] ?? 15)) ?> minutes</td></tr>
            <tr><th>Last Auto Sync</th><td><?= !empty($discordSettings['last_auto_sync_at']) ? h((string)$discordSettings['last_auto_sync_at']) . ' UTC' : '<span class="muted">Never</span>' ?></td></tr>
            <tr><th>Next Eligible</th><td>
                <?php if (empty($discordSettings['auto_sync_enabled'])): ?>
                    <span class="muted">Automatic sync is disabled</span>
                <?php elseif ($nextEligibleAutoSync instanceof DateTimeImmutable): ?>
                    <?= h($nextEligibleAutoSync->format('Y-m-d H:i:s')) ?> UTC
                <?php else: ?>
                    <span class="muted">Immediately when cron next runs</span>
                <?php endif; ?>
            </td></tr>
        </table>
        <div class="table-actions">
            <div class="hint">Configure logging, Guest DM behaviour, and automatic sync scheduling.</div>
            <a class="btn-secondary" href="/admin/discord-settings.php">Open Discord Settings</a>
        </div>
    </div>

    <div class="card">
        <h3>Sync History</h3>
        <?php if ($latestSyncRun): ?>
            <table>
                <tr><th>Latest Run</th><td>#<?= h((string)$latestSyncRun['id']) ?></td></tr>
                <?php if ($hasTriggerSource): ?><tr><th>Source</th><td><?= h(ucfirst((string)($latestSyncRun['trigger_source'] ?? 'manual'))) ?></td></tr><?php endif; ?>
                <tr><th>Status</th><td><span class="status <?= ((string)$latestSyncRun['status'] === 'completed') ? 'ok' : (((string)$latestSyncRun['status'] === 'completed_with_errors') ? 'warn' : 'bad') ?>"><?= h((string)$latestSyncRun['status']) ?></span></td></tr>
                <tr><th>Started</th><td><?= h((string)$latestSyncRun['started_at_utc']) ?> UTC</td></tr>
                <tr><th>Changed</th><td><?= h((string)($latestSyncRun['changed_members'] ?? 0)) ?> / <?= h((string)($latestSyncRun['total_members'] ?? 0)) ?></td></tr>
            </table>
        <?php else: ?>
            <p class="muted">No sync runs have been logged yet.</p>
        <?php endif; ?>
        <div class="table-actions">
            <div class="hint">Inspect full run history, per-member role changes, Guest fallbacks, DM outcomes, and trigger source.</div>
            <a class="btn-secondary" href="/admin/sync-history.php">Open Sync History</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($missingTables): ?>
    <div class="card">
        <span class="status bad">Setup Required</span>
        <p>The database schema is incomplete. Import <code>sql/schema.sql</code> and make sure these tables exist:</p>
        <ul>
            <?php foreach ($missingTables as $table): ?>
                <li><code><?= h($table) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($settingsMissingColumns): ?>
    <div class="card">
        <span class="status bad">Migration Required</span>
        <p>The <code>guild_settings</code> table is missing the new automatic sync columns.</p>
        <p class="muted small">Run <code>sql/migrations/phase3.2-auto-sync-scheduler.sql</code> before using P3.2 features.</p>
    </div>
<?php elseif ($errorMessage): ?>
    <div class="card">
        <span class="status bad">Error</span>
        <p><?= h($errorMessage) ?></p>
    </div>
<?php elseif ($status): ?>
    <div class="grid two">
        <div class="card">
            <h3>Guild</h3>
            <table>
                <tr><th>Server</th><td><?= h((string)$status['guild']['name']) ?></td></tr>
                <tr><th>Guild ID</th><td><?= h((string)$status['guild']['id']) ?></td></tr>
                <tr><th>Validation</th><td><span class="status <?= $status['ok'] ? 'ok' : 'bad' ?>"><?= $status['ok'] ? 'Ready' : 'Blocked' ?></span></td></tr>
                <tr><th>Last check</th><td><?= h(now_utc()) ?> UTC</td></tr>
            </table>
        </div>
        <div class="card">
            <h3>Bot</h3>
            <table>
                <tr><th>Bot User</th><td><?= h((string)($status['bot_user']['username'] ?? 'Unknown')) ?></td></tr>
                <tr><th>Bot ID</th><td><?= h((string)($status['bot_user']['id'] ?? '')) ?></td></tr>
                <tr><th>Highest Bot Role</th><td><?= h((string)($status['role_map'][$status['bot_highest_role']['id'] ?? '']['name'] ?? 'Unknown')) ?></td></tr>
                <tr><th>Highest Server Role</th><td><?= h((string)($status['max_server_role']['name'] ?? 'Unknown')) ?></td></tr>
                <tr><th>Mapped Roles</th><td><?= h((string)count($mappedRoleIds)) ?></td></tr>
                <tr><th>Bot-Flagged Roles</th><td><?= h((string)count($botRoleIds)) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Outcome</h3>
        <?php if ($status['ok']): ?>
            <p><span class="status ok">OK</span> The bot role is high enough and the server is ready for live sync, sync history, and scheduled auto sync.</p>
        <?php else: ?>
            <p><span class="status bad">Action Required</span> The bot role must be moved higher before mappings can be relied on.</p>
            <ul>
                <?php foreach ($status['messages'] as $message): ?>
                    <li><?= h($message) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
