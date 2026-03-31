ALTER TABLE guild_settings
    ADD COLUMN server_admin_role_id VARCHAR(32) NULL AFTER last_auto_sync_message,
    ADD COLUMN server_admin_role_name_cache VARCHAR(255) NULL AFTER server_admin_role_id,
    ADD COLUMN server_moderator_role_id VARCHAR(32) NULL AFTER server_admin_role_name_cache,
    ADD COLUMN server_moderator_role_name_cache VARCHAR(255) NULL AFTER server_moderator_role_id;
