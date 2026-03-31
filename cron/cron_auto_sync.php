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

$missingColumns = require_columns($pdo, 'guild_settings', ['auto_sync_enabled', 'auto_sync_interval_minutes', 'last_auto_sync_at']);
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

$clanName = trim((string)env('CLAN_NAME', ''));
if ($clanName === '') {
    fwrite(STDERR, "CLAN_NAME is missing from .env. Automatic sync cannot refresh the latest RuneScape clan roster without it." . PHP_EOL);
    sync_release_process_lock($lockHandle);
    exit(1);
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
            $importSummary = import_runescape_clan_members($pdo, $clanId, $clanName);
            fwrite(STDOUT, sprintf(
                'Imported latest RuneScape roster for %s: fetched=%d inserted=%d updated=%d reactivated=%d inactive_after=%d%s',
                (string)$importSummary['clan_name'],
                (int)$importSummary['fetched'],
                (int)$importSummary['inserted'],
                (int)$importSummary['updated'],
                (int)$importSummary['reactivated'],
                (int)$importSummary['marked_inactive'],
                PHP_EOL
            ));

            $summary = execute_sync_run($pdo, $guildId, $clanId, [
                'trigger_source' => 'auto',
                'initiated_by_discord_user_id' => null,
                'initiated_by_name' => 'Automatic Scheduler',
            ]);
            fwrite(STDOUT, $summary . PHP_EOL);

            $touchStmt = $pdo->prepare('UPDATE guild_settings SET last_auto_sync_at = UTC_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP WHERE clan_id = :clan_id');
            $touchStmt->execute(['clan_id' => $clanId]);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Auto sync failed for clan ' . $clanId . ': ' . $e->getMessage() . PHP_EOL);
        }
    }
} finally {
    sync_release_process_lock($lockHandle);
}
