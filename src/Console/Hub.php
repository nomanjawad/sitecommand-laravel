<?php

namespace Sitecommand\Laravel\Console;

use Illuminate\Support\Facades\Http;

/** The hub endpoints the commands use (same as the `sitecommand` npm CLI). */
final class Hub
{
    public static function url(?string $override = null): string
    {
        return $override ? rtrim($override, '/') : \Sitecommand\Laravel\Settings::hub();
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

    /** Ask the hub to run Verify against the deployed app; proves this machine holds the secret. */
    public static function verify(string $hub, string $siteId, string $secret): array
    {
        $timestamp = (string) time();
        $proof = hash_hmac('sha256', "verify\n{$siteId}\n{$timestamp}\n".hash('sha256', ''), $secret);

        return self::post($hub.'/api/node/verify', ['site_id' => $siteId, 'timestamp' => $timestamp, 'proof' => $proof]);
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
