<?php

namespace Sitecommand\Laravel;

/**
 * The connector's own settings. When the app's config is cached (most
 * production deploys), env() no longer reads .env, so a SITECOMMAND_* line
 * pasted into .env through a file manager would be ignored until someone runs
 * `php artisan config:cache` — impossible without a terminal. So the
 * connector reads its own keys straight from the .env file in that case.
 */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $fromFile = null;

    private const KEYS = ['secret' => 'SITECOMMAND_SECRET', 'site_id' => 'SITECOMMAND_SITE_ID', 'hub' => 'SITECOMMAND_HUB'];

    public static function secret(): ?string
    {
        $v = self::get('secret');

        return $v === null || $v === '' ? null : $v;
    }

    public static function siteId(): ?string
    {
        $v = self::get('site_id');

        return $v === null || $v === '' ? null : $v;
    }

    /** Sitecommand's main address. */
    public const MAIN_HUB = 'https://sitecomand.xyz';

    /** Tried in order when the main address cannot be reached (for example if the domain lapses). */
    public const FALLBACK_HUBS = ['https://siteconnector.vercel.app'];

    /** The hub to show and to write into .env: the first of hubs(). */
    public static function hub(): string
    {
        return self::hubs()[0];
    }

    /**
     * The hubs to try, in order. Sitecommand's own addresses always go main
     * first, then the fallbacks, whichever of them SITECOMMAND_HUB names (so an
     * .env written with the old vercel.app address still prefers the main one).
     * Any other SITECOMMAND_HUB (a self-hosted hub) is used alone.
     *
     * @return non-empty-list<string>
     */
    public static function hubs(): array
    {
        return self::hubsFor(self::get('hub'));
    }

    /** @return non-empty-list<string> Pure. */
    public static function hubsFor(?string $configured): array
    {
        $known = array_merge([self::MAIN_HUB], self::FALLBACK_HUBS);
        $configured = rtrim(trim((string) $configured), '/');
        if ($configured === '' || in_array(strtolower($configured), $known, true)) {
            return $known;
        }

        return [$configured];
    }

    /**
     * Whether the setup page is on. It switches itself off (SITECOMMAND_SETUP=false
     * in .env) once Verify passes; checked on every setup request, because with
     * cached routes the routes exist whatever the config said at boot.
     */
    public static function setupOn(): bool
    {
        if (app()->configurationIsCached()) {
            $file = self::fromFile();
            if (isset($file['SITECOMMAND_SETUP']) && $file['SITECOMMAND_SETUP'] !== '') {
                return ! in_array(strtolower($file['SITECOMMAND_SETUP']), ['false', '0', 'off', 'no', '(false)'], true);
            }
        }

        return (bool) config('sitecommand.setup.enabled', true);
    }

    private static function get(string $key): ?string
    {
        if (app()->configurationIsCached()) {
            $file = self::fromFile();
            if (isset($file[self::KEYS[$key]]) && $file[self::KEYS[$key]] !== '') {
                return $file[self::KEYS[$key]];
            }
        }
        $v = config('sitecommand.'.$key);

        return is_string($v) ? trim($v) : null;
    }

    /** @return array<string, string> */
    private static function fromFile(): array
    {
        if (self::$fromFile !== null) {
            return self::$fromFile;
        }
        $path = app()->environmentFilePath();

        return self::$fromFile = is_readable($path) ? self::parse((string) file_get_contents($path)) : [];
    }

    /**
     * SITECOMMAND_* lines from .env text (plain, "quoted" or 'quoted'). Pure.
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        $out = [];
        if (preg_match_all('/^\s*(?:export\s+)?(SITECOMMAND_[A-Z_]+)\s*=\s*(.*?)\s*$/m', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $key, $value]) {
                if (preg_match('/^"(.*)"$/', $value, $q) || preg_match("/^'(.*)'$/", $value, $q)) {
                    $value = $q[1];
                } else {
                    $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
                }
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Forget what was read (after writing .env, and in tests). */
    public static function flush(): void
    {
        self::$fromFile = null;
    }
}
