<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';
require_login();

$pdo = db();
$guildId = (string)env('DISCORD_GUILD_ID', '');
$clanId = (int)env('CLAN_ID', '1');

$missingTables = require_tables($pdo, ['guild_settings']);
$missingColumns = [];
if (!$missingTables) {
    $missingColumns = require_columns($pdo, 'guild_settings', [
        'log_channel_id',
        'log_channel_name_cache',
        'send_guest_dm',
        'guest_dm_message',
    ]);
}

if (!$missingTables && !$missingColumns && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();

    try {
        $guild = discord_get_guild($guildId);
        $channels = discord_get_guild_text_channels($guildId);
        $validChannelIds = [];
        $channelNames = [];
        foreach ($channels as $channel) {
            $channelId = (string)($channel['id'] ?? '');
            if ($channelId === '') {
                continue;
            }
            $validChannelIds[$channelId] = true;
            $channelNames[$channelId] = (string)($channel['name'] ?? '');
        }

        $logChannelId = trim((string)($_POST['log_channel_id'] ?? ''));
        if ($logChannelId !== '' && !isset($validChannelIds[$logChannelId])) {
            throw new RuntimeException('Please choose a valid guild text channel for the log channel.');
        }

        $sendGuestDm = isset($_POST['send_guest_dm']) ? 1 : 0;
        $guestDmMessage = trim((string)($_POST['guest_dm_message'] ?? ''));
        if ($sendGuestDm === 1 && $guestDmMessage === '') {
            throw new RuntimeException('Guest private message contents cannot be blank while guest PMs are enabled.');
        }

        $stmt = $pdo->prepare('INSERT INTO guild_settings (
                clan_id,
                discord_guild_id,
                guild_name_cache,
                log_channel_id,
                log_channel_name_cache,
                send_guest_dm,
                guest_dm_message
            ) VALUES (
                :clan_id,
                :guild_id,
                :guild_name,
                :log_channel_id,
                :log_channel_name,
                :send_guest_dm,
                :guest_dm_message
            )
            ON DUPLICATE KEY UPDATE
                discord_guild_id = VALUES(discord_guild_id),
                guild_name_cache = VALUES(guild_name_cache),
                log_channel_id = VALUES(log_channel_id),
                log_channel_name_cache = VALUES(log_channel_name_cache),
                send_guest_dm = VALUES(send_guest_dm),
                guest_dm_message = VALUES(guest_dm_message)');

        $stmt->execute([
            'clan_id' => $clanId,
            'guild_id' => $guildId,
            'guild_name' => (string)($guild['name'] ?? ''),
            'log_channel_id' => $logChannelId !== '' ? $logChannelId : null,
            'log_channel_name' => $logChannelId !== '' ? ($channelNames[$logChannelId] ?? null) : null,
            'send_guest_dm' => $sendGuestDm,
            'guest_dm_message' => $guestDmMessage !== '' ? $guestDmMessage : null,
        ]);

        flash('success', 'Discord settings saved.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/admin/discord-settings.php');
}

$settings = [
    'guild_name_cache' => '',
    'log_channel_id' => '',
    'log_channel_name_cache' => '',
    'send_guest_dm' => 0,
    'guest_dm_message' => "Hi {discord_display_name},\n\nYour Discord roles have been updated because we could not match your account to an active clan member.\n\nIf this is incorrect, please contact staff and make sure your Discord nickname matches your RuneScape name.",
];
$channels = [];
$guild = null;

if (!$missingTables && !$missingColumns) {
    try {
        $guild = discord_get_guild($guildId);
        $channels = discord_get_guild_text_channels($guildId);

        $stmt = $pdo->prepare('SELECT * FROM guild_settings WHERE clan_id = :clan_id LIMIT 1');
        $stmt->execute(['clan_id' => $clanId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $settings = array_merge($settings, $row);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin/index.php');
    }
}

require_once __DIR__ . '/../../app/views/header.php';
?>
<div class="card">
    <h2>Discord Settings</h2>
    <p class="muted">Configure where sync activity should be logged and what message should be sent when a member is moved to Guest.</p>
</div>

<?php if ($missingTables): ?>
    <div class="card">
        <span class="status bad">Setup Required</span>
        <p>Missing table(s): <?= h(implode(', ', $missingTables)) ?></p>
        <p class="muted small">Run the new migration in <code>sql/migrations/phase2.1-discord-settings.sql</code>.</p>
    </div>
<?php elseif ($missingColumns): ?>
    <div class="card">
        <span class="status bad">Migration Required</span>
        <p>The <code>guild_settings</code> table is missing these columns:</p>
        <ul>
            <?php foreach ($missingColumns as $column): ?>
                <li><code><?= h($column) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small">Run <code>sql/migrations/phase2.1-discord-settings.sql</code> before using this page.</p>
    </div>
<?php else: ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(post_csrf_token()) ?>">

    <div class="grid two">
        <div class="card" style="margin-bottom:0">
            <h3>Guild</h3>
            <table>
                <tbody>
                    <tr><th>Server</th><td><?= h((string)($guild['name'] ?? $settings['guild_name_cache'] ?? '')) ?></td></tr>
                    <tr><th>Guild ID</th><td class="mono"><?= h($guildId) ?></td></tr>
                    <tr><th>Text Channels</th><td><?= h((string)count($channels)) ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-bottom:0">
            <h3>Supported Placeholders</h3>
            <div class="role-chip-wrap">
                <?php foreach (['{discord_display_name}', '{discord_username}', '{rsn}', '{guild_name}', '{guest_role}', '{clan_member_role}'] as $placeholder): ?>
                    <span class="code-badge"><?= h($placeholder) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="small muted" style="margin-top:10px">These values will be available when guest DMs are sent during live sync changes.</p>
        </div>
    </div>

    <div class="grid two" style="margin-top:18px">
        <div>
            <label for="log_channel_id"><strong>Log Channel</strong></label>
            <select id="log_channel_id" name="log_channel_id">
                <option value="">No log channel configured</option>
                <?php foreach ($channels as $channel): ?>
                    <?php $channelId = (string)($channel['id'] ?? ''); ?>
                    <option value="<?= h($channelId) ?>" <?= $channelId === (string)($settings['log_channel_id'] ?? '') ? 'selected' : '' ?>>
                        #<?= h((string)($channel['name'] ?? 'unknown')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Used for sync logs and Guest DM failures once live apply is enabled.</p>
        </div>

        <div>
            <label class="inline" style="margin-top:28px">
                <input type="checkbox" name="send_guest_dm" value="1" <?= !empty($settings['send_guest_dm']) ? 'checked' : '' ?>>
                <span><strong>Enable Private Message on Change to Guest</strong></span>
            </label>
            <p class="hint">When enabled, the app will attempt to DM members who are moved to the Guest role during a live sync.</p>
        </div>
    </div>

    <div style="margin-top:18px">
        <label for="guest_dm_message"><strong>Guest Private Message Contents</strong></label>
        <textarea id="guest_dm_message" name="guest_dm_message" placeholder="Enter the message sent when a member is changed to Guest."><?= h((string)($settings['guest_dm_message'] ?? '')) ?></textarea>
        <p class="hint">This message is only used if Guest private messages are enabled.</p>
    </div>

    <div class="table-actions">
        <div class="hint">Settings are stored per configured guild/clan.</div>
        <button class="btn-primary" type="submit">Save Discord Settings</button>
    </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/footer.php'; ?>
