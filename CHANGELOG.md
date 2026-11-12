# Changelog

## 0.4.0

- **Main address first, vercel.app as fallback**: connect, the approval check and verify (setup page and the `sitecommand:connect` / `sitecommand:verify` commands) go to `https://sitecomand.xyz` and fall back to `https://siteconnector.vercel.app` only when the main address cannot be reached (no connection, a 5xx, or an answer that is not JSON such as a parking page). A JSON answer from the main address, even an error, is final. An `.env` that still says the vercel.app address gets the same order; a self-hosted `SITECOMMAND_HUB` or `--hub` is used alone. Connect now writes `SITECOMMAND_HUB=https://sitecomand.xyz`.

## 0.3.0

- **The setup page switches itself off once Verify passes**: it writes `SITECOMMAND_SETUP=false` into `.env` (or shows the line to paste when `.env` is not writable) and every setup route answers 404 from then on, checked on each request so cached routes and cached config follow it. Delete the line to open the page again.
- `Hub::writeEnv` no longer replaces the whole `setup` config array when it writes `SITECOMMAND_SETUP`.

## 0.2.1

- Setup page: an Inertia visit (for example the login form's redirect back to `/sitecommand`) now gets Inertia's full-page answer (409 + `X-Inertia-Location`) instead of the HTML, which Inertia showed in a sandboxed iframe where Connect and Verify could not submit.

## 0.2.0

- **Setup page at `/sitecommand`** for hosts without a terminal: status, Connect with a code (writes the secret into `.env`, or shows the lines to paste when `.env` is not writable), Verify / recheck from the browser. Behind the app's login and the `viewSitecommand` gate (default: local, or the emails in `SITECOMMAND_ADMINS`); 404 for everyone else. No JavaScript.
- With cached config the connector reads its own `SITECOMMAND_` keys from `.env` directly, so keys pasted with a file manager work without `php artisan config:cache`.

## 0.1.1

Laravel 12 and 13 only: every Laravel 11 release now carries security advisories, so Composer refuses to install them.

## 0.1.0

First release: signed `info`, `health`, `errors`, `deps`, `actions` routes (same contract as `@sitecommand/node`), built-in health checks for Laravel (database, cache, storage, disk, queue, maintenance, config), errors read from the app's own log, Composer and npm dependencies with their ecosystem, registered health checks and actions, masking, and the `sitecommand:connect`, `sitecommand:verify` and `sitecommand:check` commands.
