<?php

namespace Sitecommand\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Sitecommand\Laravel\Settings;

class SetupTest extends TestCase
{
    private string $envDir;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sitecommand.setup.admins', ['owner@example.com']);
        $app['config']->set('app.url', 'https://shop.example.com');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->envDir = sys_get_temp_dir().'/sc-env-'.bin2hex(random_bytes(4));
        @mkdir($this->envDir);
        file_put_contents($this->envDir.'/.env', "APP_NAME=Shop\n");
        $app->useEnvironmentPath($this->envDir);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    private function owner(): GenericUser
    {
        return new GenericUser(['id' => 1, 'email' => 'owner@example.com']);
    }

    private const SITE_ID = '11111111-2222-3333-4444-555555555555';

    private const SITE = 'https://shop.example.com';

    private function startFake(array $extra = []): void
    {
        Http::fake($extra + [
            '*/api/connect/device/poll' => Http::response(['status' => 'approved', 'site_secret' => str_repeat('c', 64), 'site_id' => self::SITE_ID, 'base_path' => '/api/sitecommand', 'site_url' => self::SITE]),
            '*/api/connect/device' => Http::response(['device_code' => str_repeat('d', 40), 'user_code' => 'ABCD-EFGH', 'verification_uri_complete' => 'https://sitecomand.xyz/connect/device?code=ABCD-EFGH', 'expires_in' => 600, 'interval' => 3]),
            '*/api/node/verify' => Http::response(['ok' => true, 'steps' => [['id' => 'route', 'ok' => true, 'label' => 'Route and secret', 'detail' => 'Accepted.']]]),
            '*/api/node/summary' => Http::response($this->summary()),
        ]);
    }

    private function summary(): array
    {
        return [
            'site' => ['name' => 'Shop', 'url' => self::SITE, 'framework' => 'laravel', 'connector' => '0.4.0', 'runtime' => '8.4.1', 'verified_at' => now()->subMinute()->toIso8601String(), 'dashboard_url' => 'https://sitecomand.xyz/acme/sites/1'],
            'health' => ['status' => 'up', 'label' => 'Online', 'ms' => 420, 'checked_at' => now()->subMinutes(5)->toIso8601String()],
            'uptime' => ['pct30' => 99.95, 'pct7' => 100, 'avg_ms7' => 380, 'days' => [['day' => '2026-11-11', 'pct' => 100], ['day' => '2026-11-12', 'pct' => 97]]],
            'ssl' => ['expires_at' => now()->addDays(40)->toIso8601String(), 'problem' => null],
            'domain' => ['expires_at' => now()->addDays(200)->toIso8601String(), 'published' => true],
            'dependencies' => ['total' => 120, 'vulnerable' => 1, 'worst' => 'high', 'top' => [['name' => 'guzzlehttp/guzzle', 'version' => '7.0.0', 'count' => 2, 'severity' => 'high']]],
            'problems' => [['kind' => 'slow', 'label' => 'Site slow', 'since' => now()->subHour()->toIso8601String()]],
        ];
    }

    private function connectedConfig(): void
    {
        config(['sitecommand.secret' => str_repeat('c', 64), 'sitecommand.site_id' => self::SITE_ID]);
    }

    public function test_only_allowed_users_see_the_page(): void
    {
        $this->get('/sitecommand')->assertRedirect('/login');
        $this->actingAs(new GenericUser(['id' => 2, 'email' => 'someone@example.com']))->get('/sitecommand')->assertNotFound();
        $this->actingAs($this->owner())->get(self::SITE.'/sitecommand')->assertOk()->assertSee('Connect this site')->assertSee('Connect with Sitecommand')->assertDontSee('Site status');
    }

    public function test_an_inertia_visit_is_sent_to_a_full_page_load(): void
    {
        // Inertia would otherwise show the page in a sandboxed iframe where forms are blocked.
        $this->actingAs($this->owner())
            ->get('/sitecommand', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://localhost/sitecommand');
        // Not allowed stays a 404, Inertia or not.
        $this->actingAs(new GenericUser(['id' => 2, 'email' => 'someone@example.com']))
            ->get('/sitecommand', ['X-Inertia' => 'true'])->assertNotFound();
    }

    public function test_connect_sends_you_to_sitecommand_with_a_way_back(): void
    {
        $this->startFake();
        $this->actingAs($this->owner());
        $res = $this->post(self::SITE.'/sitecommand/connect');
        $res->assertRedirect('https://sitecomand.xyz/connect/device?code=ABCD-EFGH&return='.rawurlencode(self::SITE.'/sitecommand/callback'));
        // The address connected is the one the page is open on; no form field needed.
        Http::assertSent(fn ($r) => $r->url() === 'https://sitecomand.xyz/api/connect/device' && $r['framework'] === 'laravel' && $r['site_url'] === self::SITE);
    }

    public function test_back_from_sitecommand_the_site_saves_verifies_and_shows_the_popup(): void
    {
        $this->startFake();
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/connect');
        // Back from Sitecommand: the same "Finishing the connection" page as the WordPress plugin, which goes on to finish.
        $this->get(self::SITE.'/sitecommand/callback?sc=approved')->assertOk()->assertSee('Finishing the connection')->assertSee('/sitecommand/finish', false);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/poll'));
        $this->get(self::SITE.'/sitecommand/finish')->assertRedirect('/sitecommand');

        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringStartsWith("APP_NAME=Shop\n", $env);
        $this->assertStringContainsString('SITECOMMAND_SECRET='.str_repeat('c', 64), $env);
        $this->assertStringContainsString('SITECOMMAND_SITE_ID='.self::SITE_ID, $env);
        $this->assertStringContainsString('SITECOMMAND_HUB=https://sitecomand.xyz', $env);
        $this->assertStringNotContainsString('SITECOMMAND_SETUP', $env);
        // Verify ran by itself, with the new secret.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/node/verify') && $r['site_id'] === self::SITE_ID && $r['proof'] === hash_hmac('sha256', "verify\n".self::SITE_ID."\n{$r['timestamp']}\n".hash('sha256', ''), str_repeat('c', 64)));

        $page = $this->get(self::SITE.'/sitecommand')->assertOk();
        $page->assertSee('Verified')->assertSee('Back to the website')->assertSee('See the status');
        // The page now shows the site's status from Sitecommand.
        $page->assertSee('Site status')->assertSee('Online · 420 ms')->assertSee('99.95% · last 30 days')->assertSee('1 of 120 with known vulnerabilities')
            ->assertSee('Site slow')->assertSee('guzzlehttp/guzzle')->assertSee('Open in Sitecommand')->assertDontSee('Connect this site');
        // The popup shows once.
        $this->get(self::SITE.'/sitecommand')->assertOk()->assertDontSee('Back to the website');
        // A second visit has nothing left to collect.
        $this->get(self::SITE.'/sitecommand/callback?sc=approved')->assertRedirect('/sitecommand');
        $this->get(self::SITE.'/sitecommand/finish')->assertRedirect('/sitecommand');
    }

    public function test_cancelled_in_sitecommand(): void
    {
        $this->startFake();
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/connect');
        $this->get(self::SITE.'/sitecommand/callback?sc=denied')->assertRedirect('/sitecommand');
        $this->get(self::SITE.'/sitecommand')->assertSee('cancelled in Sitecommand')->assertSee('Connect with Sitecommand');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/poll'));
    }

    public function test_a_failed_automatic_verify_explains_and_keeps_the_secret(): void
    {
        $this->startFake(['*/api/node/verify' => Http::response(['ok' => false, 'steps' => [['id' => 'route', 'ok' => false, 'label' => 'Route and secret', 'detail' => 'No answer.', 'fix' => 'Deploy first.']]], 409)]);
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/connect');
        $this->get(self::SITE.'/sitecommand/callback?sc=approved')->assertOk();
        $this->get(self::SITE.'/sitecommand/finish');
        $this->assertStringContainsString('SITECOMMAND_SECRET=', (string) file_get_contents($this->envDir.'/.env'));
        $this->get(self::SITE.'/sitecommand')->assertSee('could not verify the site yet')->assertSee('Deploy first.')->assertDontSee('Back to the website');
    }

    public function test_hubs_go_main_first_then_the_fallback(): void
    {
        $both = ['https://sitecomand.xyz', 'https://siteconnector.vercel.app'];
        $this->assertSame($both, Settings::hubsFor(null));
        $this->assertSame($both, Settings::hubsFor(''));
        // An .env written with the old address still prefers the main one.
        $this->assertSame($both, Settings::hubsFor('https://siteconnector.vercel.app/'));
        $this->assertSame($both, Settings::hubsFor('https://sitecomand.xyz'));
        // A self-hosted hub is used alone.
        $this->assertSame(['https://hub.agency.test'], Settings::hubsFor('https://hub.agency.test/'));
        $this->assertSame('https://sitecomand.xyz', Settings::hub());
    }

    public function test_connect_falls_back_when_the_main_address_is_down(): void
    {
        Http::fake([
            'sitecomand.xyz/*' => Http::response('<html>parked</html>', 200, ['Content-Type' => 'text/html']),
            'siteconnector.vercel.app/api/connect/device' => Http::response(['device_code' => str_repeat('d', 40), 'user_code' => 'WXYZ-1234', 'verification_uri_complete' => 'https://siteconnector.vercel.app/connect/device?code=WXYZ-1234', 'expires_in' => 600, 'interval' => 3]),
        ]);
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/connect')->assertRedirect('https://siteconnector.vercel.app/connect/device?code=WXYZ-1234&return='.rawurlencode(self::SITE.'/sitecommand/callback'));
        Http::assertSent(fn ($r) => $r->url() === 'https://sitecomand.xyz/api/connect/device');
    }

    public function test_a_json_answer_from_the_main_address_is_final(): void
    {
        Http::fake([
            'sitecomand.xyz/api/connect/device' => Http::response(['message' => 'Site limit reached.'], 403),
            'siteconnector.vercel.app/*' => Http::response(['device_code' => 'x'], 200),
        ]);
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/connect')->assertRedirect('/sitecommand');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'vercel.app'));
        $this->get(self::SITE.'/sitecommand')->assertSee('Site limit reached.');
    }

    public function test_the_status_falls_back_and_says_when_it_cannot_load(): void
    {
        $this->connectedConfig();
        Http::fake(['sitecomand.xyz/*' => Http::response('Bad gateway', 502), 'siteconnector.vercel.app/api/node/summary' => Http::response($this->summary())]);
        $this->actingAs($this->owner())->get(self::SITE.'/sitecommand')->assertOk()->assertSee('Online · 420 ms');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/node/summary') && $r['proof'] === hash_hmac('sha256', "summary\n".self::SITE_ID."\n{$r['timestamp']}\n".hash('sha256', ''), str_repeat('c', 64)));
    }

    public function test_a_site_sitecommand_no_longer_knows_says_so(): void
    {
        $this->connectedConfig();
        Http::fake(['*/api/node/summary' => Http::response(['error' => 'not_found'], 404)]);
        $this->actingAs($this->owner())->get(self::SITE.'/sitecommand')->assertOk()->assertSee('does not know this site')->assertSee('Reconnect');
    }

    public function test_verify_again_shows_the_popup_or_the_checklist(): void
    {
        $this->connectedConfig();
        $this->startFake();
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/verify')->assertRedirect('/sitecommand');
        $this->get(self::SITE.'/sitecommand')->assertSee('Back to the website');
        $this->assertStringNotContainsString('SITECOMMAND_SETUP', (string) file_get_contents($this->envDir.'/.env'));
    }

    public function test_a_failed_verify_shows_the_checklist(): void
    {
        $this->connectedConfig();
        $this->startFake(['*/api/node/verify' => Http::response(['ok' => false, 'steps' => [['id' => 'route', 'ok' => false, 'label' => 'Route and secret', 'detail' => 'No answer.', 'fix' => 'Deploy first.']]], 409)]);
        $this->actingAs($this->owner());
        $this->post(self::SITE.'/sitecommand/verify')->assertRedirect('/sitecommand');
        $this->get(self::SITE.'/sitecommand')->assertOk()->assertSee('Route and secret')->assertSee('Deploy first.')->assertSee('Not verified yet');
    }

    public function test_the_page_can_still_be_switched_off_by_hand(): void
    {
        $this->connectedConfig();
        config(['sitecommand.setup.enabled' => false]);
        $this->actingAs($this->owner())->get('/sitecommand')->assertNotFound();
        $this->post('/sitecommand/connect')->assertNotFound();
        $this->get('/sitecommand/callback')->assertNotFound();
        $this->get('/sitecommand/finish')->assertNotFound();
        // The connector itself keeps answering (unsigned = its own plain 404).
        $this->get('/api/sitecommand/info')->assertNotFound()->assertSee('Not Found');
    }

    public function test_with_cached_config_the_switch_is_read_from_env(): void
    {
        $this->assertTrue(Settings::setupOn());
        file_put_contents($this->envDir.'/.env', "APP_NAME=Shop\nSITECOMMAND_SETUP=false\n");
        $this->app->instance('config_loaded_from_cache', true);
        Settings::flush();
        try {
            $this->assertFalse(Settings::setupOn());
            $this->actingAs($this->owner())->get('/sitecommand')->assertNotFound();
        } finally {
            $this->app->instance('config_loaded_from_cache', false);
            Settings::flush();
        }
    }

    public function test_verify_without_a_site_id_explains(): void
    {
        $this->actingAs($this->owner());
        $this->post('/sitecommand/verify')->assertRedirect('/sitecommand');
        $this->get('/sitecommand')->assertSee('Not connected yet');
    }

    public function test_setup_routes_use_web_and_auth_but_the_connector_does_not(): void
    {
        $this->assertSame(['web', 'auth'], Route::getRoutes()->getByName('sitecommand.setup')->gatherMiddleware());
        $this->assertSame([], Route::getRoutes()->getByName('sitecommand.connector')->gatherMiddleware());
    }

    public function test_with_cached_config_the_keys_are_read_from_env_itself(): void
    {
        // What Laravel records when it boots from bootstrap/cache/config.php (as after `php artisan config:cache`).
        // Laravel 13 reads a flag, Laravel 12 checks the file: set both, undo both.
        app()->instance('config_loaded_from_cache', true);
        $cacheFile = app()->getCachedConfigPath();
        $wroteFile = ! is_file($cacheFile) && @file_put_contents($cacheFile, '<?php return [];') !== false;
        try {
            $this->assertTrue(app()->configurationIsCached());
            config(['sitecommand.secret' => null, 'sitecommand.site_id' => null]);
            file_put_contents($this->envDir.'/.env', "APP_NAME=Shop\nSITECOMMAND_SECRET=".str_repeat('e', 64)."\nSITECOMMAND_SITE_ID=abc\n");
            Settings::flush();
            $this->assertSame(str_repeat('e', 64), Settings::secret());
            $this->assertSame('abc', Settings::siteId());

            // and the connector accepts requests signed with it
            $nonce = 'nonce-cached-01';
            $res = $this->signed('GET', 'info', '', $nonce, null, str_repeat('e', 64));
            $res->assertOk();
        } finally {
            app()->instance('config_loaded_from_cache', false);
            if ($wroteFile) {
                @unlink($cacheFile);
            }
            Settings::flush();
        }
    }

    public function test_env_lines_are_parsed(): void
    {
        $this->assertSame(
            ['SITECOMMAND_SECRET' => 'abc', 'SITECOMMAND_SITE_ID' => 'x y', 'SITECOMMAND_HUB' => 'https://h'],
            Settings::parse("APP_KEY=1\nSITECOMMAND_SECRET=abc # note\nSITECOMMAND_SITE_ID=\"x y\"\nexport SITECOMMAND_HUB='https://h'\n#SITECOMMAND_OTHER=1")
        );
    }
}
