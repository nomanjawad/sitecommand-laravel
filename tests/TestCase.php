<?php

namespace Sitecommand\Laravel\Tests;

use Orchestra\Testbench\TestCase as Base;
use Sitecommand\Laravel\Signature;
use Sitecommand\Laravel\SitecommandServiceProvider;

abstract class TestCase extends Base
{
    protected const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function getPackageProviders($app): array
    {
        return [SitecommandServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sitecommand.secret', self::SECRET);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /** A request signed the way the hub signs it. */
    protected function signed(string $method, string $route, string $body = '', ?string $nonce = null, ?int $ts = null, string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $nonce ??= bin2hex(random_bytes(12));
        $path = '/api/sitecommand/'.$route.'?sc_nonce='.$nonce;
        $ts = (string) ($ts ?? time());
        $headers = [
            'HTTP_X_SITECOMMAND_TIMESTAMP' => $ts,
            'HTTP_X_SITECOMMAND_SIGNATURE' => Signature::sign($secret, Signature::stringToSign($ts, $method, $path, $body)),
            'CONTENT_TYPE' => 'application/json',
        ];

        return $this->call($method, $path, [], [], [], $headers, $body === '' ? null : $body);
    }

    protected function assertSignedBy(\Illuminate\Testing\TestResponse $res, string $nonce): void
    {
        $this->assertSame(Signature::responseSignature(self::SECRET, $nonce, $res->getContent()), $res->headers->get('X-Sitecommand-Response-Signature'));
    }
}
