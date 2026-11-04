<?php

namespace Sitecommand\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Sitecommand\Laravel\Signature;
use Sitecommand\Laravel\SitecommandServiceProvider;

/** Check the setup from inside the app, then call the connector over HTTP like the hub does. */
class CheckCommand extends Command
{
    protected $signature = 'sitecommand:check {--url= : Address to call (default: APP_URL)}';

    protected $description = 'Check the Sitecommand connector setup and call it like the hub does';

    public function handle(): int
    {
        $ok = true;
        $secret = (string) \Sitecommand\Laravel\Settings::secret();
        $base = SitecommandServiceProvider::basePath();

        $ok = $this->report(strlen($secret) >= 32, 'Secret', strlen($secret) >= 32 ? 'SITECOMMAND_SECRET is set.' : 'SITECOMMAND_SECRET is missing or too short.', 'Run php artisan sitecommand:connect, open /'.trim((string) config('sitecommand.setup.path', 'sitecommand'), '/').' in the browser, or copy the secret from the dashboard into .env.') && $ok;
        $registered = Route::has('sitecommand.connector');
        $ok = $this->report($registered, 'Route', $registered ? "Registered at {$base}/…" : 'The connector route is not registered.', app()->routesAreCached() ? 'Routes are cached: run php artisan route:cache again.' : 'Is the package discovered? Run php artisan package:discover.') && $ok;
        $url = ConnectCommand::origin((string) ($this->option('url') ?: config('app.url')));
        $this->report($url !== null && str_starts_with($url, 'https://'), 'APP_URL', $url ? "Uses {$url}" : 'APP_URL is not an https:// address.', 'Set APP_URL to the public https:// address, or pass --url.');

        if ($url !== null && strlen($secret) >= 32) {
            $nonce = bin2hex(random_bytes(12));
            $path = $base.'/info?'.Signature::NONCE_PARAM.'='.$nonce;
            $ts = (string) time();
            try {
                $res = Http::timeout(15)->withHeaders([
                    Signature::HEADER_TIMESTAMP => $ts,
                    Signature::HEADER_SIGNATURE => Signature::sign($secret, Signature::stringToSign($ts, 'GET', $path, '')),
                    'User-Agent' => 'sitecommand-laravel-check',
                ])->withOptions(['allow_redirects' => false])->get($url.$path);
                $signed = hash_equals(Signature::responseSignature($secret, $nonce, $res->body()), strtolower((string) $res->header(Signature::HEADER_RESPONSE_SIGNATURE)));
                $detail = $res->ok() && $signed ? 'Signed answer from '.$url.$base.'/info' : "HTTP {$res->status()}".($signed ? '' : ', not signed by the connector');
                $fix = $res->status() === 404 ? 'The deployed app does not have the package or the secret yet (or the secret differs from this .env). Deploy, then run this on the server.' : (in_array($res->status(), [301, 302, 307, 308], true) ? 'The address redirects: use the final https:// address in APP_URL.' : 'Something in front of the app answers this path (a cache or firewall): exclude '.$base.' from it.');
                $ok = $this->report($res->ok() && $signed, 'Live call', $detail, $fix) && $ok;
            } catch (\Throwable $e) {
                $ok = $this->report(false, 'Live call', 'Could not reach '.$url.': '.$e->getMessage(), 'Check the address and that the app is running.') && $ok;
            }
        }

        $ok ? $this->info('✓ Ready.') : $this->warn('Fix the items above.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function report(bool $ok, string $label, string $detail, string $fix): bool
    {
        $this->line(($ok ? '  <info>✓</info> ' : '  <error>✗</error> ').$label.' — '.$detail);
        if (! $ok) {
            $this->line('      <comment>'.$fix.'</comment>');
        }

        return $ok;
    }
}
