# Phase 2 — First-Run Setup Wizard

This package is a clean Phase 2 implementation for the RS3 Clan -> Discord Ranking App foundation.

## Included
- requirements checks
- DB connection test
- schema creation
- config file writer
- install lock
- initial settings seed
- first admin bootstrap prep

## Upload Notes
Point your webroot at `/public`.

## Install Behaviour
- If `config/config.php` and `storage/install/installed.lock` do not both exist, the app redirects to `/install`.
- Once install succeeds, `/install` is blocked and `/` becomes the app entry point.

## First Admin Bootstrap
The installer creates a one-time token in `install_bootstrap` and shows a handoff link:

`/install/admin-bootstrap?token={token}`

That token is intentionally a prep step for the upcoming Discord OAuth claim flow in Phase 3.

## Important
This zip is a clean Phase 2 package. If your current Phase 1 skeleton already has overlapping files, merge carefully or deploy this as the refreshed codebase for the next step.
