ALTER TABLE guild_settings
    ADD COLUMN log_channel_id VARCHAR(32) NULL AFTER validation_message,
    ADD COLUMN log_channel_name_cache VARCHAR(255) NULL AFTER log_channel_id,
    ADD COLUMN send_guest_dm TINYINT(1) NOT NULL DEFAULT 0 AFTER log_channel_name_cache,
    ADD COLUMN guest_dm_message TEXT NULL AFTER send_guest_dm;
