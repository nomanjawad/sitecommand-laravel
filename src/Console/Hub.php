<?php

namespace Sitecommand\Laravel\Console;

use Illuminate\Support\Facades\Http;

/** The hub endpoints the commands use (same as the `sitecommand` npm CLI). */
final class Hub
{
    public static function url(?string $override = null): string
    {
        return self::hubs($override)[0];
    }

    /**
     * The hubs to try: a --hub given on the command line alone, else
     * Settings::hubs() (main address, then the fallbacks).
     *
     * @return non-empty-list<string>
     */
    public static function hubs(?string $override = null, ?string $prefer = null): array
    {
        $list = $override ? [rtrim($override, '/')] : \Sitecommand\Laravel\Settings::hubs();
        if ($prefer !== null && in_array($prefer, $list, true)) {
            $list = array_values(array_unique(array_merge([$prefer], $list)));
        }

        return $list;
    }

    /**
     * POST to the first hub that answers. The next hub is tried only when this
     * one could not be reached: no connection, a 5xx, or an answer that is not
     * JSON (a parking page if the domain lapsed). A JSON answer, even an
     * error, is the hub's answer and is returned as is.
     *
     * @param  list<string>  $hubs
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function call(array $hubs, string $path, array $body, int $timeout = 60): array
    {
        $last = [0, ['message' => 'No hub to call.'], $hubs[0] ?? ''];
        foreach ($hubs as $hub) {
            [$status, $json] = self::post($hub.$path, $body, $timeout);
            $last = [$status, $json, $hub];
            if ($status !== 0 && $status < 500 && is_array($json)) {
                return $last;
            }
        }

        return $last;
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    public static function post(string $url, array $body, int $timeout = 60): array
    {
        try {
            $res = Http::timeout($timeout)->withHeaders(['User-Agent' => 'sitecommand-laravel'])->acceptJson()->post($url, $body);

            return [$res->status(), is_array($res->json()) ? $res->json() : null];
        } catch (\Throwable $e) {
            return [0, ['message' => $e->getMessage()]];
        }
    }

    /**
     * Ask the hub to run Verify against the deployed app; proves this machine
     * holds the secret. Tries the hubs in order like call().
     *
     * @param  string|list<string>  $hubs
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function verify(string|array $hubs, string $siteId, string $secret): array
    {
        $timestamp = (string) time();

        return self::call((array) $hubs, '/api/node/verify', ['site_id' => $siteId, 'timestamp' => $timestamp, 'proof' => self::proof($secret, 'verify', $siteId, $timestamp)]);
    }

    /**
     * This site's status as Sitecommand sees it (status, uptime, SSL, domain,
     * vulnerable packages, open problems), for the setup page. Same proof as
     * Verify with its own purpose, so one cannot be replayed as the other.
     *
     * @param  list<string>  $hubs
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function summary(array $hubs, string $siteId, string $secret): array
    {
        $timestamp = (string) time();

        return self::call($hubs, '/api/node/summary', ['site_id' => $siteId, 'timestamp' => $timestamp, 'proof' => self::proof($secret, 'summary', $siteId, $timestamp)], 8);
    }

    /** HMAC of "<purpose>\n<site id>\n<timestamp>\n<sha256 of empty>" with the secret (hub lib/modules/node/proof.ts). Pure. */
    public static function proof(string $secret, string $purpose, string $siteId, string $timestamp): string
    {
        return hash_hmac('sha256', "{$purpose}\n{$siteId}\n{$timestamp}\n".hash('sha256', ''), $secret);
    }

    /** Print a Verify report. */
    public static function printReport(\Illuminate\Console\Command $cmd, ?array $report): bool
    {
        if (! $report || ! isset($report['steps'])) {
            $cmd->error('The hub did not return a report'.(isset($report['message']) ? ': '.$report['message'] : '.'));

            return false;
        }
        foreach ($report['steps'] as $s) {
            $line = ($s['ok'] ? '  <info>✓</info> ' : '  <error>✗</error> ').$s['label'].' — '.$s['detail'];
            $cmd->line($line);
            if (! $s['ok'] && ! empty($s['fix'])) {
                $cmd->line('      <comment>'.$s['fix'].'</comment>');
            }
        }

        return (bool) ($report['ok'] ?? false);
    }

    /**
     * Write the keys into the app's .env (keeping everything else) and make
     * them live: runtime config now, and the config cache rebuilt if there is one.
     */
    public static function writeEnv(array $values): bool
    {
        $path = app()->environmentFilePath();
        $before = is_readable($path) ? (string) file_get_contents($path) : '';
        if (@file_put_contents($path, self::upsertEnv($before, $values)) === false) {
            return false;
        }
        foreach ($values as $k => $v) {
            if ($k === 'SITECOMMAND_SETUP') {
                // The setup switch lives inside the `setup` array; never replace the array.
                config(['sitecommand.setup.enabled' => ! in_array(strtolower((string) $v), ['false', '0', 'off', 'no'], true)]);

                continue;
            }
            config(['sitecommand.'.strtolower(substr($k, strlen('SITECOMMAND_'))) => $v]);
        }
        \Sitecommand\Laravel\Settings::flush();
        if (app()->configurationIsCached()) {
            try {
                \Illuminate\Support\Facades\Artisan::call('config:cache');
            } catch (\Throwable) {
                // The connector reads its keys from .env itself while config is cached.
            }
        }

        return true;
    }

    /** Set keys in a .env file's text, keeping everything else. Pure. */
    public static function upsertEnv(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $re = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($re, $text)) {
                $text = preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $text, 1);
            } else {
                $text = rtrim($text, "\n").($text === '' ? '' : "\n\n").($text === '' || ! str_contains($text, '# Sitecommand') ? "# Sitecommand (php artisan sitecommand:connect)\n" : '').$line."\n";
            }
        }

        return $text;
    }
}
