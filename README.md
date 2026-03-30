# RS3 Clan Discord Ranker - Phase 1.1 patch pack

This patch pack keeps the PHP-only architecture and fixes the first-round setup/auth issues.

## What changed in Phase 1.1

- Fixed first-login admin authorisation bug in Discord OAuth callback
- Hardened session handling
- Login page now redirects straight to dashboard when already signed in
- Dashboard now handles missing schema tables gracefully instead of fatalling
- Added setup checks to clan members, role mappings, role flags, and user mappings pages
- Role mappings now use fixed RuneScape rank order
- Role mappings warn when selected roles sit above the bot
- Role flags page now shows which roles are already mapped to RuneScape ranks
- User mappings continue to store **manual mappings only**
- Blank user mappings remain **runtime-only nickname fallback**

## Important behaviour

- Only **manual user mappings** are saved.
- If a Discord member has no manual mapping, runtime logic should fall back to **nickname searching**.
- Nickname matches are shown only as a **preview** in the admin UI.
- The dashboard checks that the bot's highest role is above any mapped or bot-managed roles.

## Upgrade steps

1. Back up your current deployed files.
2. Replace the project files with this patch pack.
3. Keep your existing `.env`.
4. If you already imported `sql/schema.sql`, there is no required DB migration for this patch.
5. Log in again and test dashboard, role mappings, role flags, clan members, and user mappings.

## Requirements

- PHP 8.1+
- `pdo_mysql`
- `curl`
- MySQL or MariaDB
- A Discord application with:
  - bot token
  - OAuth client ID / secret
  - redirect URI configured
  - the bot invited to the target guild

## Notes

- This is still Phase 1 only. It does not perform automated role sync jobs yet.
- If your guild is large, the user mappings page may take longer because it reads guild members directly from Discord.
- The `Clan Members` page exists so you can seed and maintain the clan member list without needing another tool first.
