<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');
$pdo = db();
$requiredTables = ['guild_settings', 'rs_rank_mappings', 'discord_role_flags', 'discord_user_mappings', 'clan_members'];
$missingTables = require_tables($pdo, $requiredTables);

$status = null;
$errorMessage = null;
$mappedRoleIds = [];
$botRoleIds = [];

if (!$missingTables) {
    $mappedStmt = $pdo->prepare('SELECT discord_role_id FROM rs_rank_mappings WHERE clan_id = :clan_id AND discord_role_id IS NOT NULL AND discord_role_id <> ""');
    $mappedStmt->execute(['clan_id' => $clanId]);
    $mappedRoleIds = array_map('strval', $mappedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $botStmt = $pdo->prepare('SELECT discord_role_id FROM discord_role_flags WHERE discord_guild_id = :guild_id AND is_bot_role = 1');
    $botStmt->execute(['guild_id' => $guildId]);
    $botRoleIds = array_map('strval', $botStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

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

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Server Readiness</h2>
    <p class="muted">This checks the bot installation, guild access and role hierarchy directly through Discord's REST API using the bot token in your <code>.env</code>.</p>
</div>

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
            <p><span class="status ok">OK</span> The bot role is high enough and the server is ready for Phase 1 administration.</p>
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
