<?php

namespace Sitecommand\Laravel;

/** The info route: what the app is and what it exposes. */
final class Info
{
    /** @return array<string, mixed> */
    public static function build(Sitecommand $registry): array
    {
        return [
            'ok' => true,
            'connector' => ['name' => 'sitecommand/laravel', 'version' => Sitecommand::VERSION],
            'framework' => 'laravel',
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'app' => ['name' => config('sitecommand.app_name') ?: config('app.name'), 'version' => config('sitecommand.app_version') ?: self::appVersion()],
            'environment' => app()->environment(),
            'platform' => PHP_OS_FAMILY.'-'.php_uname('m'),
            'server' => isset($_SERVER['SERVER_SOFTWARE']) ? Mask::text(explode(' ', (string) $_SERVER['SERVER_SOFTWARE'])[0], 60) : PHP_SAPI,
            'region' => null,
            'host' => null,
            'health_checks' => array_keys($registry->healthChecks()),
            'actions' => array_keys($registry->actions()),
        ];
    }

    /** version.json {"version": …} (written by many deploys), else composer.json "version". */
    private static function appVersion(): ?string
    {
        foreach ([base_path('version.json'), base_path('composer.json')] as $file) {
            if (is_readable($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                    return substr($data['version'], 0, 40);
                }
            }
        }

        return null;
    }
}
