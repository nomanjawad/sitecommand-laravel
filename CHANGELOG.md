# Changelog

## 0.2.0

- **Setup page at `/sitecommand`** for hosts without a terminal: status, Connect with a code (writes the secret into `.env`, or shows the lines to paste when `.env` is not writable), Verify / recheck from the browser. Behind the app's login and the `viewSitecommand` gate (default: local, or the emails in `SITECOMMAND_ADMINS`); 404 for everyone else. No JavaScript.
- With cached config the connector reads its own `SITECOMMAND_` keys from `.env` directly, so keys pasted with a file manager work without `php artisan config:cache`.

## 0.1.1

Laravel 12 and 13 only: every Laravel 11 release now carries security advisories, so Composer refuses to install them.

## 0.1.0

First release: signed `info`, `health`, `errors`, `deps`, `actions` routes (same contract as `@sitecommand/node`), built-in health checks for Laravel (database, cache, storage, disk, queue, maintenance, config), errors read from the app's own log, Composer and npm dependencies with their ecosystem, registered health checks and actions, masking, and the `sitecommand:connect`, `sitecommand:verify` and `sitecommand:check` commands.
