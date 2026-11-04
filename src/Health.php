<?php

namespace Sitecommand\Laravel;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** The health route: built-in checks for a Laravel app plus the developer's own. */
final class Health
{
    public function __construct(private readonly Sitecommand $registry) {}

    /** @return array{ok: bool, checks: array<string, array<string, mixed>>} */
    public function run(): array
    {
        $on = (array) config('sitecommand.checks', []);
        $checks = [
            'php' => fn () => ['ok' => true, 'version' => PHP_VERSION, 'memory_limit' => (string) ini_get('memory_limit'), 'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)],
            'maintenance' => fn () => app()->isDownForMaintenance() ? ['ok' => false, 'status' => 'fail', 'message' => 'The app is in maintenance mode (php artisan down).'] : ['ok' => true],
        ];
        if ($on['database'] ?? true) {
            $checks['database'] = function () {
                $connection = DB::connection();
                $connection->select('select 1');

                return ['ok' => true, 'driver' => $connection->getDriverName()];
            };
        }
        if ($on['cache'] ?? true) {
            $checks['cache'] = function () {
                $store = Cache::store();
                $key = 'sitecommand:health:'.bin2hex(random_bytes(6));
                $store->put($key, 'ok', 30);
                $ok = $store->get($key) === 'ok';
                $store->forget($key);

                return $ok ? ['ok' => true, 'driver' => (string) config('cache.default')] : ['ok' => false, 'status' => 'fail', 'message' => 'The cache did not keep a value.'];
            };
        }
        if ($on['storage'] ?? true) {
            $checks['storage'] = function () {
                $missing = array_values(array_filter([storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs')], fn ($d) => ! is_dir($d) || ! is_writable($d)));

                return $missing === [] ? ['ok' => true] : ['ok' => false, 'status' => 'fail', 'message' => 'Not writable: '.implode(', ', array_map(fn ($d) => self::relative($d), $missing))];
            };
        }
        if ($on['disk'] ?? true) {
            $checks['disk'] = function () {
                $free = @disk_free_space(base_path());
                $total = @disk_total_space(base_path());
                if (! $free || ! $total) {
                    return ['ok' => true, 'message' => 'Disk space is not readable on this host.'];
                }
                $pct = round($free / $total * 100, 1);
                $freeMb = (int) round($free / 1048576);
                $out = ['ok' => $freeMb > 200, 'free_mb' => $freeMb, 'free_percent' => $pct];
                if ($freeMb <= 200) {
                    $out += ['status' => 'fail', 'message' => "Only {$freeMb} MB free."];
                } elseif ($pct < 10) {
                    $out += ['status' => 'warn', 'message' => "{$pct}% of the disk is free."];
                }

                return $out;
            };
        }
        if ($on['queue'] ?? true) {
            $checks['queue'] = function () {
                $driver = (string) config('queue.default');
                $out = ['ok' => true, 'driver' => $driver];
                $table = (string) config('queue.failed.table', 'failed_jobs');
                if (Schema::hasTable($table)) {
                    $failed = DB::table($table)->count();
                    $out['failed_jobs'] = $failed;
                    if ($failed > 0) {
                        $out += ['status' => 'warn', 'message' => "{$failed} failed job(s) waiting (php artisan queue:failed)."];
                    }
                }

                return $out;
            };
        }
        if ($on['config'] ?? true) {
            $checks['config'] = function () {
                $production = app()->environment('production');
                $out = ['ok' => true, 'environment' => app()->environment(), 'debug' => (bool) config('app.debug'), 'config_cached' => app()->configurationIsCached(), 'routes_cached' => app()->routesAreCached()];
                if ($production && config('app.debug')) {
                    $out += ['status' => 'warn', 'message' => 'APP_DEBUG is on in production: error pages show code and settings to visitors.'];
                }

                return $out;
            };
        }

        $results = [];
        foreach ($checks + $this->registry->healthChecks() as $name => $check) {
            $results[$name] = self::runOne($check);
        }
        $ok = true;
        foreach ($results as $r) {
            $ok = $ok && $r['ok'];
        }

        return ['ok' => $ok, 'checks' => $results];
    }

    /** @return array<string, mixed> */
    private static function runOne(Closure $check): array
    {
        $started = microtime(true);
        try {
            $out = $check();
            $r = $out === null || $out === true ? ['ok' => true] : ($out === false ? ['ok' => false] : (is_array($out) ? $out : ['ok' => (bool) $out]));
            $r['ok'] = (bool) ($r['ok'] ?? false);
            if (isset($r['message'])) {
                $r['message'] = Mask::text((string) $r['message'], 300);
            }
        } catch (Throwable $e) {
            $r = ['ok' => false, 'status' => 'fail', 'message' => Mask::text($e->getMessage(), 300)];
        }
        $r['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $r;
    }

    public static function relative(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
