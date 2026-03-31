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

## Phase 1.2 import update

- Added direct RuneScape clan roster import from `members_lite.ws`
- New `CLAN_NAME` setting in `.env`
- The `Clan Members` page can now import the live clan roster and mark missing members inactive
- Import parsing reuses the hardened RSN cleanup and normalisation approach from your existing sync logic
- Safety guard: if the API parses 0 members, no database writes are made


## Phase 1.3

This pack improves the User Mappings page with:
- manual-only saved mappings
- nickname match preview that is never auto-saved
- search and status filters
- mapping summary cards
- better visibility into nickname normalisation


## Patch notes

- Clan Members page is now read-only for RSN, rank, and active status; RuneScape remains the source of truth.
- User Mapping dropdowns now include a built-in search field that filters the RSN list by name, normalised RSN, or rank.


## Phase 1.4 patch
This patch adds:
- multi-select role mappings so one RuneScape rank can map to multiple Discord roles
- Guest and Clan Member mapping rows
- Role Flags renamed to Role Management
- Is Bot wording, with Discord-managed roles forced to true

### Existing installs
Run the migration in:
`sql/migrations/phase1.4-role-mapping-multiselect.sql`

before opening the updated Role Mappings page.


## Phase 2.0 – Sync Preview

This pack adds a dry-run **Sync Preview** page at `/admin/sync-preview.php`.

It:
- resolves Discord users to RuneScape members using manual mappings first, nickname fallback second
- reads imported clan rank data
- reads current role mappings and role management flags
- shows current roles, target roles, add/remove previews, and blocked hierarchy cases
- does **not** apply any Discord role changes yet


## Phase 2.1 migration

Run `sql/migrations/phase2.1-discord-settings.sql` before using the Discord Settings page.


## Phase 3.2 – Automatic sync scheduler

This pack adds:
- automatic sync settings on the Discord Settings page
- a cron-safe runner at `cron/cron_auto_sync.php`
- `trigger_source` on `sync_runs` so Sync History can distinguish manual vs automatic runs

### Existing installs
Run the migration in:
`sql/migrations/phase3.2-auto-sync-scheduler.sql`

before enabling automatic sync.

### Cron example
```bash
*/5 * * * * /usr/bin/php /path/to/project/cron/cron_auto_sync.php >> /path/to/project/storage/logs/auto-sync.log 2>&1
```

The cron runner uses the same live sync engine as **Run Sync Now**.
