# RS3 Clan Discord Ranker - Phase 1 (PHP-only replacement)

This replacement package removes the separate Node proxy service.

## What this build includes

- Discord OAuth admin login in PHP
- Direct Discord REST calls from PHP using the bot token
- Bot/server readiness dashboard
- Clan member management page
- RuneScape rank -> Discord role mapping
- Role flagging (`bot role`, `protected role`)
- Discord user -> RSN manual mapping
- Runtime fallback to nickname matching when no manual mapping exists

## Important behaviour

- Only **manual user mappings** are saved.
- If a Discord member has no manual mapping, runtime logic should fall back to **nickname searching**.
- Nickname matches are shown only as a **preview** in the admin UI.
- The dashboard checks that the bot's highest role is at the **top of the server role hierarchy** and above any mapped or bot-managed roles.

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

## Folder layout

- `public/` - web root
- `app/` - shared PHP code
- `sql/` - schema
- `.env.example` - sample environment file

## Setup

1. Copy `.env.example` to `.env` and fill in your real values.
2. Import `sql/schema.sql`.
3. Point your web root at `public/`.
4. In Discord Developer Portal, ensure the redirect URI exactly matches `DISCORD_REDIRECT_URI`.
5. Invite the bot to the server and move the bot role to the top of your server role list.

## Login flow

- Login uses the `identify` OAuth scope.
- After OAuth, PHP uses the bot token to confirm the user is in the configured guild.
- If `ADMIN_DISCORD_USER_IDS` or `ADMIN_DISCORD_ROLE_IDS` is set, those values are enforced.

## Notes

- This is Phase 1 only. It does not perform automated role sync jobs yet.
- If your guild is large, the user mappings page may take longer because it reads guild members directly from Discord.
- The new `Clan Members` page exists so you can seed and maintain the clan member list without needing another tool first.
