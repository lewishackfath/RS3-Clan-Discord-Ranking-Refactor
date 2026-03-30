# RS3 Clan Discord Ranker - Phase 1

Phase 1 delivers a working admin interface for:

- Discord OAuth admin login
- Environment-based configuration
- Bot/server readiness validation
- RuneScape clan rank -> Discord role mapping
- Role flagging (`bot role`, `protected role`)
- Discord user -> RSN manual mapping
- Runtime fallback to nickname matching when no manual mapping exists

## Stack

- **PHP 8.1+** for the admin UI
- **MariaDB / MySQL** for storage
- **Node.js 20+** for the Discord bot/service
- **discord.js 14** for guild validation and future sync work

## Important behaviour

- The admin UI stores **manual user mappings only**.
- If a Discord member has no manual mapping, the bot uses **nickname matching at runtime**.
- Nickname matches are **not saved** into the database by default.
- On first load, the dashboard checks whether the bot's highest role sits above the roles it may need to manage.

## Project layout

- `public/` - web root
- `app/` - shared PHP code
- `bot/` - Node bot/service
- `sql/` - schema
- `.env.example` - sample environment file

## Setup

### 1. Create the database

Import:

```sql
sql/schema.sql
```

### 2. Create your environment file

Copy `.env.example` to `.env` and update values.

### 3. Install bot dependencies

```bash
cd bot
npm install
```

### 4. Run the bot

```bash
npm start
```

### 5. Point your web root at

```text
public/
```

## OAuth scopes

The login flow expects these scopes:

- `identify`
- `guilds`

If you want to read guild member details directly with user tokens later, you can extend this, but Phase 1 uses the bot token for guild data and the OAuth login for admin identity.

## Suggested Discord bot permissions

At minimum, invite the bot with permissions needed for future role work. A conservative starting set is:

- View Channels
- Manage Roles
- Read Message History

Administrator is not required, but the bot **must** have its role above any roles it is expected to manage.

## Admin access

A user may access the admin UI if either of the following is true:

1. Their Discord user ID is listed in `ADMIN_DISCORD_USER_IDS`
2. They hold one of the roles listed in `ADMIN_DISCORD_ROLE_IDS`

## Notes

This is intentionally a clean Phase 1 foundation. It does not yet perform full automated role syncs.
