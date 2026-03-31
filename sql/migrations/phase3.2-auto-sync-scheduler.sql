ALTER TABLE guild_settings
    ADD COLUMN IF NOT EXISTS auto_sync_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER guest_dm_message,
    ADD COLUMN IF NOT EXISTS auto_sync_interval_minutes INT NOT NULL DEFAULT 15 AFTER auto_sync_enabled,
    ADD COLUMN IF NOT EXISTS last_auto_sync_at DATETIME NULL AFTER auto_sync_interval_minutes;

ALTER TABLE sync_runs
    ADD COLUMN IF NOT EXISTS trigger_source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER initiated_by_name;
