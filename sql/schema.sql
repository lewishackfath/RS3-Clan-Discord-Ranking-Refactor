CREATE TABLE IF NOT EXISTS clan_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    rsn VARCHAR(32) NOT NULL,
    rsn_normalised VARCHAR(32) NOT NULL,
    rank_name VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clan_member_rsn (clan_id, rsn_normalised),
    KEY idx_clan_members_clan_active (clan_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guild_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    discord_guild_id VARCHAR(32) NOT NULL,
    guild_name_cache VARCHAR(255) NULL,
    bot_user_id VARCHAR(32) NULL,
    bot_role_id VARCHAR(32) NULL,
    bot_role_name_cache VARCHAR(255) NULL,
    last_validation_at DATETIME NULL,
    validation_status VARCHAR(32) NULL,
    validation_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guild_settings_clan (clan_id),
    UNIQUE KEY uq_guild_settings_guild (discord_guild_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rs_rank_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    rs_rank_name VARCHAR(64) NOT NULL,
    discord_role_id VARCHAR(32) NULL,
    discord_role_name_cache VARCHAR(255) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rs_rank_mappings_rank (clan_id, rs_rank_name),
    UNIQUE KEY uq_rs_rank_mappings_role (clan_id, rs_rank_name, discord_role_id),
    KEY idx_rs_rank_mappings_role (discord_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_role_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    discord_guild_id VARCHAR(32) NOT NULL,
    discord_role_id VARCHAR(32) NOT NULL,
    role_name_cache VARCHAR(255) NULL,
    position_cache INT NULL,
    is_bot_role TINYINT(1) NOT NULL DEFAULT 0,
    is_protected_role TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_discord_role_flags (discord_guild_id, discord_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_user_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    discord_guild_id VARCHAR(32) NOT NULL,
    discord_user_id VARCHAR(32) NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    rsn_cache VARCHAR(32) NOT NULL,
    discord_username_cache VARCHAR(255) NULL,
    discord_nickname_cache VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_discord_user_mappings (clan_id, discord_guild_id, discord_user_id),
    KEY idx_discord_user_mappings_member (member_id),
    CONSTRAINT fk_discord_user_mappings_member
        FOREIGN KEY (member_id) REFERENCES clan_members(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
VALUES
    (1, 'Guest', NULL, NULL, 1),
    (1, 'Clan Member', NULL, NULL, 1),
    (1, 'Recruit', NULL, NULL, 1),
    (1, 'Corporal', NULL, NULL, 1),
    (1, 'Sergeant', NULL, NULL, 1),
    (1, 'Lieutenant', NULL, NULL, 1),
    (1, 'Captain', NULL, NULL, 1),
    (1, 'General', NULL, NULL, 1),
    (1, 'Coordinator', NULL, NULL, 1),
    (1, 'Overseer', NULL, NULL, 1),
    (1, 'Deputy Owner', NULL, NULL, 1),
    (1, 'Owner', NULL, NULL, 1)
ON DUPLICATE KEY UPDATE rs_rank_name = VALUES(rs_rank_name);
