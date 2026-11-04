# sitecommand/laravel

Connect a Laravel app to [Sitecommand](https://siteconnector.vercel.app): health (database, cache, storage, disk, queue, config and your own checks), recent server errors from the app's log, Composer and npm dependencies (checked for known vulnerabilities by the hub) and the actions **you** choose to expose — for your dashboard and for your AI assistant through Sitecommand.

- **Read-only by design.** It never runs arbitrary code, writes files, runs shell commands or reads your `.env`. The only things that can run are the actions you register.
- **Signed requests only.** Every request is signed with your site secret (HMAC-SHA256, ±5 minutes, single-use nonce). Anything else gets a plain `404`, so the route does not even reveal it exists. Every answer is signed back, so nothing in between can fake it.
- **No middleware.** The route skips the `web` group: no sessions, CSRF, page caches or HTML error pages touch it.
- Laravel 11, 12 and 13 · PHP 8.2+.

## Install

```bash
composer require sitecommand/laravel
```

Deploy the app as usual, then **on the server** (where the live `.env` is):

```bash
php artisan sitecommand:connect
```

It prints a code, you approve it in the Sitecommand dashboard, and it writes `SITECOMMAND_SECRET`, `SITECOMMAND_SITE_ID` and `SITECOMMAND_HUB` into `.env` (and re-caches the config if it was cached). Then it asks the hub to verify the app.

No shell on the server? Use **Add app → Laravel** in the dashboard instead: it shows the secret once; put it in the server's `.env` as `SITECOMMAND_SECRET=…`, run `php artisan config:cache` if you cache config, and press **Verify**.

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
