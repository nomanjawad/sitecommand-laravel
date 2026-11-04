<?php

namespace Sitecommand\Laravel;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sitecommand\Laravel\Http\ConnectorController;
use Sitecommand\Laravel\Http\SetupController;

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

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sitecommand');

        // Who may open the setup page. Apps override it with their own Gate::define (app providers boot later).
        if (! Gate::has('viewSitecommand')) {
            Gate::define('viewSitecommand', function ($user = null): bool {
                if ($this->app->environment('local')) {
                    return $user !== null;
                }
                $email = is_object($user) && isset($user->email) ? strtolower((string) $user->email) : '';

                return $email !== '' && in_array($email, array_map('strtolower', (array) config('sitecommand.setup.admins', [])), true);
            });
        }

        if (config('sitecommand.setup.enabled', true) && ! $this->app->routesAreCached()) {
            $path = '/'.trim((string) config('sitecommand.setup.path', 'sitecommand'), '/');
            Route::middleware((array) config('sitecommand.setup.middleware', ['web', 'auth']))->group(function () use ($path) {
                Route::get($path, [SetupController::class, 'show'])->name('sitecommand.setup');
                Route::post($path.'/connect', [SetupController::class, 'connect'])->middleware('throttle:10,10')->name('sitecommand.setup.connect');
                Route::post($path.'/verify', [SetupController::class, 'verify'])->middleware('throttle:20,10')->name('sitecommand.setup.verify');
                Route::post($path.'/cancel', [SetupController::class, 'cancel'])->name('sitecommand.setup.cancel');
            });
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
