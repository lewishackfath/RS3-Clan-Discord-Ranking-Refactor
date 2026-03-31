#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$pdo = db();
$requiredTables = ['guild_settings', 'rs_rank_mappings', 'discord_role_flags', 'discord_user_mappings', 'clan_members', 'sync_runs', 'sync_run_members'];
$missingTables = require_tables($pdo, $requiredTables);
if ($missingTables) {
    fwrite(STDERR, "Missing required tables: " . implode(', ', $missingTables) . PHP_EOL);
    exit(1);
}

$missingColumns = require_columns($pdo, 'guild_settings', ['auto_sync_enabled', 'auto_sync_interval_minutes', 'last_auto_sync_at', 'last_roster_import_at', 'last_roster_import_status', 'last_roster_import_message', 'last_auto_sync_status', 'last_auto_sync_message']);
if ($missingColumns) {
    fwrite(STDERR, "Missing required guild_settings columns: " . implode(', ', $missingColumns) . PHP_EOL);
    fwrite(STDERR, "Run sql/migrations/phase3.2-auto-sync-scheduler.sql first." . PHP_EOL);
    exit(1);
}

$lockHandle = sync_acquire_process_lock(__DIR__ . '/../storage/locks/auto-sync.lock');
if ($lockHandle === false) {
    fwrite(STDOUT, "Automatic sync is already running; exiting." . PHP_EOL);
    exit(0);
}

try {
    $stmt = $pdo->query('SELECT *
        FROM guild_settings
        WHERE auto_sync_enabled = 1
          AND discord_guild_id IS NOT NULL
          AND discord_guild_id <> ""
          AND auto_sync_interval_minutes > 0
          AND (
                last_auto_sync_at IS NULL
                OR last_auto_sync_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL auto_sync_interval_minutes MINUTE)
          )
        ORDER BY clan_id ASC');
    $rows = $stmt->fetchAll() ?: [];

    if ($rows === []) {
        fwrite(STDOUT, "No clans are currently eligible for automatic sync." . PHP_EOL);
        exit(0);
    }

    foreach ($rows as $row) {
        $clanId = (int)($row['clan_id'] ?? 0);
        $guildId = trim((string)($row['discord_guild_id'] ?? ''));

        if ($clanId <= 0 || $guildId === '') {
            continue;
        }

        fwrite(STDOUT, sprintf('[%s] Running auto sync for clan %d (%s)%s', gmdate('Y-m-d H:i:s'), $clanId, $guildId, PHP_EOL));

        try {
            $result = perform_auto_sync_for_clan($pdo, $clanId, $guildId, [
                'trigger_source' => 'auto',
                'initiated_by_discord_user_id' => null,
                'initiated_by_name' => 'Automatic Scheduler',
            ]);

            $import = $result['import'] ?? [];
            fwrite(STDOUT, sprintf(
                'Roster import: fetched=%d inserted=%d updated=%d reactivated=%d inactive=%d%s',
                (int)($import['fetched'] ?? 0),
                (int)($import['inserted'] ?? 0),
                (int)($import['updated'] ?? 0),
                (int)($import['reactivated'] ?? 0),
                (int)($import['marked_inactive'] ?? 0),
                PHP_EOL
            ));
            fwrite(STDOUT, (string)($result['summary'] ?? 'Automatic sync completed.') . PHP_EOL);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Auto sync failed for clan ' . $clanId . ': ' . $e->getMessage() . PHP_EOL);
        }
    }
} finally {
    sync_release_process_lock($lockHandle);
}
