-- Phase 1.4 migration
-- Allows multiple Discord roles per RuneScape rank and seeds Guest + Clan Member rows.

ALTER TABLE rs_rank_mappings
    DROP INDEX uq_rs_rank_mappings,
    ADD KEY idx_rs_rank_mappings_rank (clan_id, rs_rank_name),
    ADD UNIQUE KEY uq_rs_rank_mappings_role (clan_id, rs_rank_name, discord_role_id);

INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
SELECT clans.clan_id, 'Guest', NULL, NULL, 1
FROM (
    SELECT DISTINCT clan_id
    FROM rs_rank_mappings
    WHERE clan_id IS NOT NULL
) AS clans
WHERE NOT EXISTS (
    SELECT 1
    FROM rs_rank_mappings existing
    WHERE existing.clan_id = clans.clan_id
      AND existing.rs_rank_name = 'Guest'
);

INSERT INTO rs_rank_mappings (clan_id, rs_rank_name, discord_role_id, discord_role_name_cache, is_enabled)
SELECT clans.clan_id, 'Clan Member', NULL, NULL, 1
FROM (
    SELECT DISTINCT clan_id
    FROM rs_rank_mappings
    WHERE clan_id IS NOT NULL
) AS clans
WHERE NOT EXISTS (
    SELECT 1
    FROM rs_rank_mappings existing
    WHERE existing.clan_id = clans.clan_id
      AND existing.rs_rank_name = 'Clan Member'
);
