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
 * The setup page, like the WordPress plugin's: one button connects the site
 * (you approve it in Sitecommand and come straight back; the site then saves
 * its secret and verifies itself), and once connected the page shows the
 * site's status from Sitecommand. Behind the app's login and the
 * `viewSitecommand` gate; 404 for everyone else. No JavaScript.
 */
class SetupController
{
    private const SESSION = 'sitecommand.device';

    public function show(Request $request): Response
    {
        $this->authorize($request);

        // An Inertia app fetches this page with XHR when it follows a redirect
        // (for example the login form's redirect to the intended URL) and then
        // shows the plain HTML in a sandboxed iframe where forms cannot submit.
        // Inertia's own answer for "load this as a full page" is 409 with
        // X-Inertia-Location; nothing in the session is touched first.
        if ($request->headers->has('X-Inertia')) {
            return response('', 409)->header('X-Inertia-Location', $request->fullUrl());
        }

        $flash = $request->session()->pull('sitecommand.flash');
        $device = $request->session()->get(self::SESSION);
        $lines = $request->session()->pull('sitecommand.lines');

        // Came back without the redirect (closed the tab, approved elsewhere): finish from here.
        if (is_array($device)) {
            [$state, $message, $lines] = $this->collect($request, $device);
            if ($state === 'verified') {
                $request->session()->flash('sitecommand.popup', $this->popup($message));
                $device = null;
            } elseif ($state !== 'pending') {
                $flash = ['tone' => $state === 'failed' ? 'bad' : 'good', 'message' => $message];
                $device = null;
            }
        }

        $connected = Settings::secret() !== null && strlen((string) Settings::secret()) >= 32 && Settings::siteId() !== null;
        $summary = null;
        $summaryError = null;
        if ($connected && ! $device) {
            [$status, $body] = Hub::summary(Hub::hubs(), (string) Settings::siteId(), (string) Settings::secret());
            if ($status === 200 && is_array($body) && isset($body['site'])) {
                $summary = $body;
            } else {
                $summaryError = $status === 404
                    ? 'Sitecommand does not know this site with this secret (removed from the dashboard, or the secret was replaced). Connect again.'
                    : 'Could not load the status from Sitecommand right now'.(isset($body['message']) ? ': '.$body['message'] : '.');
            }
        }

        return response()->view('sitecommand::setup', [
            'connected' => $connected,
            'summary' => $summary,
            'summaryError' => $summaryError,
            'status' => $this->status(),
            'device' => $device,
            'flash' => $flash,
            'popup' => $request->session()->pull('sitecommand.popup'),
            'report' => $request->session()->pull('sitecommand.report'),
            'lines' => $lines,
            'base' => $this->base(),
            'siteUrl' => $this->siteUrl($request),
            'secretTail' => $connected ? substr((string) Settings::secret(), -4) : null,
            'siteId' => Settings::siteId(),
            'hubs' => Settings::hubs(),
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }

    /** Connect with Sitecommand: start a request and send the person to approve it. They come back to callback(). */
    public function connect(Request $request): Response
    {
        $this->authorize($request);
        $url = $this->siteUrl($request);
        if ($url === null) {
            return $this->back($request, 'bad', 'This site needs a public https:// address (open this page over https, or set APP_URL).');
        }
        [$status, $start, $hub] = Hub::call(Hub::hubs(), '/api/connect/device', [
            'site_url' => $url,
            'framework' => 'laravel',
            'app_name' => (string) config('app.name'),
            'base_path' => SitecommandServiceProvider::basePath(),
        ], 20);
        if ($status !== 200 || empty($start['device_code']) || empty($start['verification_uri_complete'])) {
            return $this->back($request, 'bad', 'Sitecommand could not start the connection: '.($start['message'] ?? "HTTP {$status}"));
        }
        $request->session()->put(self::SESSION, [
            'device_code' => $start['device_code'],
            'user_code' => $start['user_code'],
            'link' => $start['verification_uri_complete'],
            'expires_at' => time() + (int) ($start['expires_in'] ?? 600),
            'hub' => $hub,
            'url' => $url,
        ]);
        $back = $url.$this->base().'/callback';
        $link = (string) $start['verification_uri_complete'];

        return redirect()->away($link.(str_contains($link, '?') ? '&' : '?').'return='.rawurlencode($back));
    }

    /** Back from Sitecommand: collect the secret, save it, verify, and show the result as a popup. */
    public function callback(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $device = $request->session()->get(self::SESSION);
        if (! is_array($device)) {
            return $this->back($request, 'bad', 'This connection was already finished or has expired. If the site is not connected yet, press Connect with Sitecommand again.');
        }
        if ($request->query('sc') === 'denied') {
            $request->session()->forget(self::SESSION);

            return $this->back($request, 'bad', 'The connection was cancelled in Sitecommand.');
        }
        // Approval was just given, so the first poll normally collects; allow a short moment for it.
        for ($i = 0; $i < 3; $i++) {
            [$state, $message, $lines] = $this->collect($request, $device);
            if ($state !== 'pending') {
                break;
            }
            usleep(800_000);
        }
        if ($state === 'verified') {
            $request->session()->flash('sitecommand.popup', $this->popup($message));
        } elseif ($state === 'pending') {
            $request->session()->flash('sitecommand.flash', ['tone' => 'bad', 'message' => 'Sitecommand has not confirmed the approval yet. This page checks again by itself.']);
        } else {
            $request->session()->flash('sitecommand.flash', ['tone' => $state === 'failed' ? 'bad' : 'good', 'message' => $message]);
            if ($lines) {
                $request->session()->flash('sitecommand.lines', $lines);
            }
        }

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
            return $this->back($request, 'bad', 'Not connected yet: press Connect with Sitecommand, or put SITECOMMAND_SECRET and SITECOMMAND_SITE_ID in .env.');
        }
        [$status, $report] = Hub::verify(Hub::hubs(), $siteId, $secret);
        if ($status === 404) {
            return $this->back($request, 'bad', 'Sitecommand does not know this site with this secret (removed from the dashboard, or the secret was replaced). Connect again.');
        }
        if (! is_array($report) || ! isset($report['steps'])) {
            return $this->back($request, 'bad', 'Sitecommand did not answer'.(isset($report['message']) ? ': '.$report['message'] : '.'));
        }
        if (($report['ok'] ?? false) === true) {
            $request->session()->flash('sitecommand.popup', $this->popup('Sitecommand checked the site from the outside and everything answered.'));
        } else {
            $request->session()->flash('sitecommand.report', $report);
        }

        return redirect($this->base());
    }

    /**
     * One poll of the hub for a waiting request; on approval save the secret
     * and verify at once. States: pending, failed, connected (saved, not yet
     * verified, or .env not writable: $lines to paste), verified.
     *
     * @param  array<string, mixed>  $device
     * @return array{0: string, 1: string, 2: array<string, string>|null}
     */
    private function collect(Request $request, array $device): array
    {
        if (time() > (int) $device['expires_at']) {
            $request->session()->forget(self::SESSION);

            return ['failed', 'The connection request expired. Press Connect with Sitecommand again.', null];
        }
        // The request lives in Sitecommand's database, so any of its addresses can be asked.
        [, $poll] = Hub::call(Hub::hubs(null, (string) $device['hub']), '/api/connect/device/poll', ['device_code' => $device['device_code']], 15);
        $s = $poll['status'] ?? 'unknown';
        if ($s === 'pending' || $s === 'slow_down') {
            return ['pending', '', null];
        }
        $request->session()->forget(self::SESSION);
        if ($s !== 'approved' || empty($poll['site_secret'])) {
            return ['failed', $s === 'denied' ? 'The connection was cancelled in Sitecommand.' : ($s === 'expired' ? 'The connection request expired. Press Connect with Sitecommand again.' : 'Sitecommand no longer knows this request. Press Connect with Sitecommand again.'), null];
        }
        $values = ['SITECOMMAND_SECRET' => (string) $poll['site_secret'], 'SITECOMMAND_SITE_ID' => (string) $poll['site_id'], 'SITECOMMAND_HUB' => Settings::hub()];
        if (! Hub::writeEnv($values)) {
            // Shown once, to the signed-in admin only: the page cannot write .env here.
            return ['connected', 'Connected, but this site cannot write its .env file. Add the lines below to .env (file manager), save, then press Verify.', $values];
        }
        [$status, $report] = Hub::verify(Hub::hubs(), (string) $poll['site_id'], (string) $poll['site_secret']);
        if (is_array($report) && ($report['ok'] ?? false) === true) {
            return ['verified', 'This site is connected to Sitecommand and verified. It is monitored from now on.', null];
        }
        if (is_array($report) && isset($report['steps'])) {
            $request->session()->flash('sitecommand.report', $report);
        }

        return ['connected', 'Connected and the secret is saved, but Sitecommand could not verify the site yet. See below, fix what is marked ✗ and press Verify.', null];
    }

    /** @return array{title: string, message: string} */
    private function popup(string $message): array
    {
        return ['title' => 'Verified', 'message' => $message];
    }

    /**
     * The address to connect: the one this page is open on (what visitors and
     * Sitecommand reach), else APP_URL. Only https, or http on localhost.
     */
    private function siteUrl(Request $request): ?string
    {
        return ConnectCommand::origin($request->getSchemeAndHttpHost()) ?? ConnectCommand::origin((string) config('app.url'));
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
            ['label' => 'Site id', 'ok' => $siteId !== null, 'detail' => $siteId ?? 'Not set'],
            ['label' => 'Hub', 'ok' => true, 'detail' => implode(', then ', Settings::hubs())],
            ['label' => 'APP_URL', 'ok' => $appUrl !== null && str_starts_with($appUrl, 'https://') ? true : null, 'detail' => $appUrl ? $appUrl : 'Not an https:// address (the address this page is open on is used)'],
            ['label' => '.env', 'ok' => is_writable($env) ? true : null, 'detail' => is_writable($env) ? 'Writable: connecting saves the secret by itself.' : 'Not writable: connecting shows the lines to paste.'],
            ['label' => 'Config cache', 'ok' => null, 'detail' => app()->configurationIsCached() ? 'Cached: the connector reads its SITECOMMAND_ lines from .env directly, so edits work at once.' : 'Not cached.'],
        ];
    }

    private function authorize(Request $request): void
    {
        if (! Settings::setupOn() || ! $request->user() || ! Gate::forUser($request->user())->allows('viewSitecommand')) {
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
