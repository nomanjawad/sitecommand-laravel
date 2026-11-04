<?php

return [
    /*
     * The site secret from Sitecommand (shown once when the app is connected,
     * or written by `php artisan sitecommand:connect`). Without it every
     * request to the connector gets a plain 404.
     */
    'secret' => env('SITECOMMAND_SECRET'),

    /* The hub, and this app's id on it (written by sitecommand:connect; used by sitecommand:verify). */
    'hub' => env('SITECOMMAND_HUB', 'https://siteconnector.vercel.app'),
    'site_id' => env('SITECOMMAND_SITE_ID'),

    /* Where the connector's routes live. Keep it in step with the hub (default /api/sitecommand). */
    'base_path' => env('SITECOMMAND_BASE_PATH', '/api/sitecommand'),

    /* Shown in Sitecommand. Null = config('app.name') and version.json / composer.json "version". */
    'app_name' => env('SITECOMMAND_APP_NAME'),
    'app_version' => env('SITECOMMAND_APP_VERSION'),

    /* The cache store for single-use nonces. Null = the default store. */
    'cache_store' => env('SITECOMMAND_CACHE_STORE'),

    /* Built-in health checks. Turn one off by setting it to false. */
    'checks' => [
        'database' => true,
        'cache' => true,
        'storage' => true,
        'disk' => true,
        'queue' => true,
        'config' => true,
    ],

    /*
     * The setup page (/sitecommand by default): status, Connect with a code
     * and Verify from the browser, for hosts without a terminal. Behind the
     * app's login; who may open it is the `viewSitecommand` gate. By default
     * that is anyone in local development, else only the emails listed in
     * SITECOMMAND_ADMINS. Define the gate yourself to change it:
     *   Gate::define('viewSitecommand', fn ($user) => $user->is_admin);
     * Everyone else gets a 404.
     */
    'setup' => [
        'enabled' => (bool) env('SITECOMMAND_SETUP', true),
        'path' => env('SITECOMMAND_SETUP_PATH', 'sitecommand'),
        'middleware' => ['web', 'auth'],
        'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SITECOMMAND_ADMINS', ''))))),
    ],

    /* Errors are read from the app's own log (storage/logs). How much of its end to read. */
    'errors' => [
        'max_bytes' => 512 * 1024,
        'max_entries' => 100,
    ],
];
