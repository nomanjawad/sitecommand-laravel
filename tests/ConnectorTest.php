<?php

namespace Sitecommand\Laravel\Tests;

use Sitecommand\Laravel\Facades\Sitecommand;

class ConnectorTest extends TestCase
{
    public function test_unsigned_requests_get_a_plain_404(): void
    {
        $res = $this->get('/api/sitecommand/info');
        $res->assertStatus(404);
        $this->assertSame('Not Found', $res->getContent());
        $this->assertNull($res->headers->get('X-Sitecommand-Response-Signature'));
    }

    public function test_signed_info_is_answered_and_signed(): void
    {
        $nonce = 'nonce-info-0001';
        $res = $this->signed('GET', 'info', '', $nonce);
        $res->assertOk()->assertJsonPath('ok', true)->assertJsonPath('framework', 'laravel')->assertJsonPath('connector.name', 'sitecommand/laravel');
        $this->assertSame(PHP_VERSION, $res->json('php'));
        $this->assertSignedBy($res, $nonce);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_replay_stale_wrong_secret_and_no_secret_are_refused(): void
    {
        $this->signed('GET', 'info', '', 'nonce-replay-01')->assertOk();
        $this->signed('GET', 'info', '', 'nonce-replay-01')->assertStatus(404);
        $this->signed('GET', 'info', '', null, time() - 400)->assertStatus(404);
        $this->signed('GET', 'info', '', null, null, str_repeat('b', 64))->assertStatus(404);
        config(['sitecommand.secret' => null]);
        $this->signed('GET', 'info')->assertStatus(404);
    }

    public function test_health_runs_built_in_and_custom_checks(): void
    {
        Sitecommand::health('payments', fn () => ['ok' => false, 'message' => 'Gateway down, token=abcdef123']);
        Sitecommand::health('search', fn () => true);
        $res = $this->signed('GET', 'health');
        $res->assertOk();
        $this->assertFalse($res->json('ok'));
        $this->assertTrue($res->json('checks.database.ok'));
        $this->assertTrue($res->json('checks.cache.ok'));
        $this->assertTrue($res->json('checks.search.ok'));
        $this->assertFalse($res->json('checks.payments.ok'));
        $this->assertStringNotContainsString('abcdef123', $res->json('checks.payments.message'));
    }

    public function test_actions_list_run_and_validate(): void
    {
        Sitecommand::action('greet', 'Say hello', fn (array $in) => ['hello' => $in['name'] ?? 'world'], input: ['name' => 'string?']);
        Sitecommand::action('boom', 'Fails', fn () => throw new \RuntimeException('nope password=hunter22'), changes: true);

        $list = $this->signed('GET', 'actions');
        $list->assertOk()->assertJsonPath('actions.0.name', 'greet')->assertJsonPath('actions.1.changes', true);

        $nonce = 'nonce-action-01';
        $run = $this->signed('POST', 'actions/greet', '{"input":{"name":"Noman"}}', $nonce);
        $run->assertOk()->assertJsonPath('result.hello', 'Noman');
        $this->assertSignedBy($run, $nonce);

        $this->signed('POST', 'actions/greet', '{"input":{"name":5}}')->assertStatus(400)->assertJsonPath('code', 'bad_input');
        $this->signed('POST', 'actions/greet', '{"input":{"other":1}}')->assertStatus(400);
        $this->signed('POST', 'actions/greet', 'not json')->assertStatus(400)->assertJsonPath('code', 'bad_json');
        $this->signed('POST', 'actions/missing', '{}')->assertStatus(404)->assertJsonPath('code', 'unknown_action');
        $boom = $this->signed('POST', 'actions/boom', '{}');
        $boom->assertStatus(500)->assertJsonPath('code', 'action_failed');
        $this->assertStringNotContainsString('hunter22', $boom->json('message'));
    }

    public function test_unknown_signed_route_is_a_signed_json_404(): void
    {
        $nonce = 'nonce-unknown-1';
        $res = $this->signed('GET', 'nope', '', $nonce);
        $res->assertStatus(404)->assertJsonPath('code', 'unknown_route');
        $this->assertSignedBy($res, $nonce);
    }

    public function test_deps_and_errors_routes_answer(): void
    {
        $this->signed('GET', 'deps')->assertOk()->assertJsonPath('ok', true);
        $this->signed('GET', 'errors')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_the_route_skips_web_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('sitecommand.connector');
        $this->assertNotNull($route);
        $this->assertSame([], $route->gatherMiddleware());
    }
}
