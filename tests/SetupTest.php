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

    public function test_only_allowed_users_see_the_page(): void
    {
        $this->get('/sitecommand')->assertRedirect('/login');
        $this->actingAs(new GenericUser(['id' => 2, 'email' => 'someone@example.com']))->get('/sitecommand')->assertNotFound();
        $this->actingAs($this->owner())->get('/sitecommand')->assertOk()->assertSee('Sitecommand connector')->assertSee('Connect with Sitecommand');
    }

    public function test_connect_with_a_code_writes_env_and_shows_connected(): void
    {
        Http::fake([
            '*/api/connect/device/poll' => Http::sequence()
                ->push(['status' => 'pending'])
                ->push(['status' => 'approved', 'site_secret' => str_repeat('c', 64), 'site_id' => '11111111-2222-3333-4444-555555555555', 'base_path' => '/api/sitecommand', 'site_url' => 'https://shop.example.com']),
            '*/api/connect/device' => Http::response(['device_code' => str_repeat('d', 40), 'user_code' => 'ABCD-EFGH', 'verification_uri_complete' => 'https://hub.test/connect/device?code=ABCD-EFGH', 'expires_in' => 600, 'interval' => 3]),
        ]);
        $this->actingAs($this->owner());
        $this->post('/sitecommand/connect', ['url' => 'https://shop.example.com'])->assertRedirect('/sitecommand');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/connect/device') && $r['framework'] === 'laravel' && $r['site_url'] === 'https://shop.example.com');

        $this->get('/sitecommand')->assertOk()->assertSee('ABCD-EFGH')->assertSee('http-equiv="refresh"', false);
        $this->get('/sitecommand')->assertOk()->assertSee('Connected. The secret is saved in .env.');

        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringStartsWith("APP_NAME=Shop\n", $env);
        $this->assertStringContainsString('SITECOMMAND_SECRET='.str_repeat('c', 64), $env);
        $this->assertStringContainsString('SITECOMMAND_SITE_ID=11111111-2222-3333-4444-555555555555', $env);
        $this->assertSame(str_repeat('c', 64), Settings::secret());
    }

    public function test_verify_shows_the_hubs_checklist(): void
    {
        config(['sitecommand.site_id' => '11111111-2222-3333-4444-555555555555']);
        Http::fake(['*/api/node/verify' => Http::response(['ok' => true, 'checked_at' => 'now', 'steps' => [['id' => 'route', 'ok' => true, 'label' => 'Route and secret', 'detail' => 'Accepted.']]])]);
        $this->actingAs($this->owner());
        $this->post('/sitecommand/verify')->assertRedirect('/sitecommand');
        Http::assertSent(fn ($r) => $r['site_id'] === '11111111-2222-3333-4444-555555555555' && strlen((string) $r['proof']) === 64);
        $this->get('/sitecommand')->assertSee('Route and secret')->assertSee('Verified: the site shows as connected');
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
