<?php

namespace Sitecommand\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sitecommand\Laravel\Http\ConnectorController;

class SitecommandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sitecommand.php', 'sitecommand');
        $this->app->singleton(Sitecommand::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/sitecommand.php' => config_path('sitecommand.php')], 'sitecommand-config');

        if (! $this->app->routesAreCached()) {
            // No middleware group: no session, CSRF, page cache or HTML error
            // pages. The controller answers everything itself (404 when unsigned).
            Route::match(['GET', 'POST'], self::basePath().'/{path?}', ConnectorController::class)
                ->where('path', '.*')
                ->name('sitecommand.connector');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ConnectCommand::class, Console\VerifyCommand::class, Console\CheckCommand::class]);
        }
    }

    public static function basePath(): string
    {
        $base = '/'.trim((string) config('sitecommand.base_path', '/api/sitecommand'), '/');

        return preg_match('~^/[A-Za-z0-9/_-]{1,80}$~', $base) ? $base : '/api/sitecommand';
    }
}
