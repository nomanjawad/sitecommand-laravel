# sitecommand/laravel

Connect a Laravel app to [Sitecommand](https://siteconnector.vercel.app): health (database, cache, storage, disk, queue, config and your own checks), recent server errors from the app's log, Composer and npm dependencies (checked for known vulnerabilities by the hub) and the actions **you** choose to expose — for your dashboard and for your AI assistant through Sitecommand.

- **Read-only by design.** It never runs arbitrary code, writes files, runs shell commands or reads your `.env`. The only things that can run are the actions you register.
- **Signed requests only.** Every request is signed with your site secret (HMAC-SHA256, ±5 minutes, single-use nonce). Anything else gets a plain `404`, so the route does not even reveal it exists. Every answer is signed back, so nothing in between can fake it.
- **No middleware.** The route skips the `web` group: no sessions, CSRF, page caches or HTML error pages touch it.
- Laravel 12 and 13 · PHP 8.2+ (Laravel 11 is out of security support; Composer refuses its releases).

## Install

```bash
composer require sitecommand/laravel
```

Deploy the app as usual (any way: git, CI, or a zip uploaded through the host's file manager). Then connect it in one of three ways:

**1. From the browser (no terminal needed).** Sign in to your app and open **`/sitecommand`**. Press **Connect with Sitecommand**, approve the code in the dashboard, and the secret is saved in `.env` for you. Then press **Verify now**: Sitecommand calls the site from the outside and shows the checklist. Come back any time to recheck.

Who may open the page is the `viewSitecommand` gate. By default: anyone signed in during local development, and in production only the emails in `SITECOMMAND_ADMINS` (comma-separated, in `.env`). Usually you define it yourself in `AppServiceProvider::boot()`:

```php
Gate::define('viewSitecommand', fn ($user) => $user->hasRole('admin'));
```

Everyone else gets a 404. Turn the page off with `SITECOMMAND_SETUP=false`; move it with `SITECOMMAND_SETUP_PATH`.

**2. With the file manager.** Use **Add app → Laravel** in the dashboard: it shows the secret once. Put it in the site's `.env` (`SITECOMMAND_SECRET=…`, and `SITECOMMAND_SITE_ID=…` if you want to Verify from `/sitecommand`), save, and press **Verify** in the dashboard. No `config:cache` needed: while the config is cached, the connector reads its own `SITECOMMAND_` lines straight from `.env`.

**3. With a terminal.** On the server: `php artisan sitecommand:connect`. It prints a code, you approve it, and it writes the keys into `.env`, then asks the hub to verify.

If `.env` is not writable by the web server, the page shows the lines to paste instead.

## Commands

| Command | What it does |
|---|---|
| `php artisan sitecommand:connect` | Connect with a code and write the secret into `.env` (`--url=` if `APP_URL` is not the public address) |
| `php artisan sitecommand:verify` | Ask the hub to check the deployed app (same as the dashboard's Verify) |
| `php artisan sitecommand:check` | Check the setup locally, then call the connector over HTTP like the hub does |

## Your own checks and actions

In `AppServiceProvider::boot()`:

```php
use Sitecommand\Laravel\Facades\Sitecommand;

Sitecommand::health('payments', fn () => Payments::ping());           // true / false / ['ok' => …, 'message' => …]

Sitecommand::action('clear-page-cache', 'Clear the page cache',
    fn () => ResponseCache::clear(), changes: true);                   // changes: true needs Full scope on the hub

Sitecommand::action('recent-orders', 'Latest orders',
    fn (array $in) => Order::latest()->take($in['limit'] ?? 20)->get(['id', 'total', 'created_at']),
    input: ['limit' => 'number?']);
```

Input fields are `string`, `number` or `boolean` (add `?` for optional); unknown fields are refused. Results are sent as JSON (capped at 100 KB). Errors in checks and actions are masked before they leave the app.

## Routes

All under `/api/sitecommand` (change with `SITECOMMAND_BASE_PATH`, keep it in step with the hub): `GET info`, `GET health`, `GET errors`, `GET deps`, `GET actions`, `POST actions/{name}`.

Behind a CDN or page cache, exclude `/api/sitecommand/*` from caching. If you cache routes (`php artisan route:cache`), re-run it after installing.

## Configuration

`php artisan vendor:publish --tag=sitecommand-config` publishes `config/sitecommand.php`: the secret, hub, base path, app name/version shown in Sitecommand, the cache store for nonces, which built-in checks run, and how much of the log to read for errors.

## License

MIT
