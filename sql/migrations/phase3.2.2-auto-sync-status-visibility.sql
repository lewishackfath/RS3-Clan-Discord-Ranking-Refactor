ALTER TABLE guild_settings
    ADD COLUMN last_roster_import_at DATETIME NULL AFTER last_auto_sync_at,
    ADD COLUMN last_roster_import_status VARCHAR(32) NULL AFTER last_roster_import_at,
    ADD COLUMN last_roster_import_message TEXT NULL AFTER last_roster_import_status,
    ADD COLUMN last_auto_sync_status VARCHAR(32) NULL AFTER last_roster_import_message,
    ADD COLUMN last_auto_sync_message TEXT NULL AFTER last_auto_sync_status;
