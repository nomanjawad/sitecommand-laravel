<?php

namespace Sitecommand\Laravel\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Sitecommand\Laravel\Console\ConnectCommand;
use Sitecommand\Laravel\Console\Hub;
use Sitecommand\Laravel\Settings;
use Sitecommand\Laravel\Sitecommand;
use Sitecommand\Laravel\SitecommandServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The setup page: connect with a code, verify and recheck from the browser,
 * for hosts without a terminal (shared hosting, zip uploads). Behind the
 * app's login and the `viewSitecommand` gate; 404 for everyone else. No
 * JavaScript: while a code waits for approval the page refreshes itself.
 */
class SetupController
{
    private const SESSION = 'sitecommand.device';

    public function show(Request $request): Response
    {
        $this->authorize($request);
        $flash = $request->session()->pull('sitecommand.flash');
        $device = $request->session()->get(self::SESSION);
        $lines = null;

        if (is_array($device)) {
            [$state, $message, $lines] = $this->poll($request, $device);
            if ($state !== 'pending') {
                $flash = ['tone' => $state === 'connected' ? 'good' : 'bad', 'message' => $message];
                $device = null;
            }
        }

        return response()->view('sitecommand::setup', [
            'status' => $this->status(),
            'device' => $device,
            'flash' => $flash,
            'report' => $request->session()->pull('sitecommand.report'),
            'lines' => $lines,
            'base' => '/'.trim((string) config('sitecommand.setup.path', 'sitecommand'), '/'),
            'defaultUrl' => ConnectCommand::origin((string) config('app.url')) ?? $request->getSchemeAndHttpHost(),
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $url = ConnectCommand::origin((string) $request->input('url', config('app.url')));
        if ($url === null) {
            return $this->back($request, 'bad', 'Enter the site\'s public https:// address.');
        }
        [$status, $start] = Hub::post(Settings::hub().'/api/connect/device', [
            'site_url' => $url,
            'framework' => 'laravel',
            'app_name' => (string) config('app.name'),
            'base_path' => SitecommandServiceProvider::basePath(),
        ], 20);
        if ($status !== 200 || empty($start['device_code'])) {
            return $this->back($request, 'bad', 'Sitecommand could not start the connection: '.($start['message'] ?? "HTTP {$status}"));
        }
        $request->session()->put(self::SESSION, [
            'device_code' => $start['device_code'],
            'user_code' => $start['user_code'],
            'link' => $start['verification_uri_complete'],
            'expires_at' => time() + (int) ($start['expires_in'] ?? 600),
            'hub' => Settings::hub(),
            'url' => $url,
        ]);

        return redirect($this->base());
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $request->session()->forget(self::SESSION);

        return redirect($this->base());
    }

    public function verify(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $secret = Settings::secret();
        $siteId = Settings::siteId();
        if ($secret === null || strlen($secret) < 32 || $siteId === null) {
            return $this->back($request, 'bad', 'Not connected yet: connect first, or put SITECOMMAND_SECRET and SITECOMMAND_SITE_ID in .env.');
        }
        [$status, $report] = Hub::verify(Settings::hub(), $siteId, $secret);
        if ($status === 404) {
            return $this->back($request, 'bad', 'Sitecommand does not know this site with this secret (removed from the dashboard, or the secret was replaced). Connect again.');
        }
        if (! is_array($report) || ! isset($report['steps'])) {
            return $this->back($request, 'bad', 'Sitecommand did not answer'.(isset($report['message']) ? ': '.$report['message'] : '.'));
        }
        $request->session()->flash('sitecommand.report', $report);

        return redirect($this->base());
    }

    /**
     * One poll of the hub for a waiting code.
     *
     * @param  array<string, mixed>  $device
     * @return array{0: string, 1: string, 2: array<string, string>|null}
     */
    private function poll(Request $request, array $device): array
    {
        if (time() > (int) $device['expires_at']) {
            $request->session()->forget(self::SESSION);

            return ['failed', 'The code expired. Connect again.', null];
        }
        [, $poll] = Hub::post($device['hub'].'/api/connect/device/poll', ['device_code' => $device['device_code']], 15);
        $s = $poll['status'] ?? 'unknown';
        if ($s === 'pending' || $s === 'slow_down') {
            return ['pending', '', null];
        }
        $request->session()->forget(self::SESSION);
        if ($s !== 'approved' || empty($poll['site_secret'])) {
            return ['failed', $s === 'denied' ? 'The request was denied in Sitecommand.' : ($s === 'expired' ? 'The code expired. Connect again.' : 'Sitecommand no longer knows this request. Connect again.'), null];
        }
        $values = ['SITECOMMAND_SECRET' => (string) $poll['site_secret'], 'SITECOMMAND_SITE_ID' => (string) $poll['site_id'], 'SITECOMMAND_HUB' => (string) $device['hub']];
        if (! Hub::writeEnv($values)) {
            // Shown once, to the signed-in admin only: the page cannot write .env here.
            return ['connected', 'Connected, but .env is not writable here. Add the lines below to the site\'s .env (file manager), save, then press Verify.', $values];
        }

        return ['connected', 'Connected. The secret is saved in .env. Press Verify to have Sitecommand check the site.', null];
    }

    /** @return list<array{label: string, ok: bool|null, detail: string}> */
    private function status(): array
    {
        $secret = Settings::secret();
        $siteId = Settings::siteId();
        $env = app()->environmentFilePath();
        $appUrl = ConnectCommand::origin((string) config('app.url'));

        return [
            ['label' => 'Connector', 'ok' => true, 'detail' => 'sitecommand/laravel '.Sitecommand::VERSION.', route '.SitecommandServiceProvider::basePath().'/…'.(Route::has('sitecommand.connector') ? '' : ' (not registered: re-cache routes)')],
            ['label' => 'Secret', 'ok' => $secret !== null && strlen($secret) >= 32, 'detail' => $secret !== null && strlen($secret) >= 32 ? 'Set (…'.substr($secret, -4).')' : 'Not set'],
            ['label' => 'Site id', 'ok' => $siteId !== null, 'detail' => $siteId ?? 'Not set (needed for Verify from here)'],
            ['label' => 'Hub', 'ok' => true, 'detail' => Settings::hub()],
            ['label' => 'Site address', 'ok' => $appUrl !== null && str_starts_with($appUrl, 'https://'), 'detail' => $appUrl ? "APP_URL is {$appUrl}" : 'APP_URL is not an https:// address'],
            ['label' => '.env', 'ok' => is_writable($env) ? true : null, 'detail' => is_writable($env) ? 'Writable: Connect saves the secret by itself.' : 'Not writable: Connect shows the lines to paste.'],
            ['label' => 'Config cache', 'ok' => null, 'detail' => app()->configurationIsCached() ? 'Cached: the connector reads its SITECOMMAND_ lines from .env directly, so edits work at once.' : 'Not cached.'],
        ];
    }

    private function authorize(Request $request): void
    {
        if (! $request->user() || ! Gate::forUser($request->user())->allows('viewSitecommand')) {
            abort(404);
        }
    }

    private function back(Request $request, string $tone, string $message): RedirectResponse
    {
        $request->session()->flash('sitecommand.flash', ['tone' => $tone, 'message' => $message]);

        return redirect($this->base());
    }

    private function base(): string
    {
        return '/'.trim((string) config('sitecommand.setup.path', 'sitecommand'), '/');
    }
}
