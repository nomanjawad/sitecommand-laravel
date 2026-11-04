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

    public static function hub(): string
    {
        return rtrim(self::get('hub') ?: 'https://siteconnector.vercel.app', '/');
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
