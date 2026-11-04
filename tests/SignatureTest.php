<?php

namespace Sitecommand\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Sitecommand\Laravel\Signature;

class SignatureTest extends TestCase
{
    public function test_shared_fixture_matches_the_hub(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/hmac-signing.json'), true);
        $this->assertNotEmpty($fixture['cases']);
        foreach ($fixture['cases'] as $c) {
            $this->assertSame($c['body_sha256'], hash('sha256', $c['raw_body']), $c['name']);
            $this->assertSame($c['string_to_sign'], Signature::stringToSign($c['timestamp'], $c['method'], $c['path'], $c['raw_body']), $c['name']);
            $this->assertSame($c['signature'], Signature::sign($fixture['site_secret'], $c['string_to_sign']), $c['name']);
        }
    }

    public function test_fixture_is_identical_to_the_hubs_copy_when_checked_out_next_to_it(): void
    {
        $hub = __DIR__.'/../../hub/fixtures/hmac-signing.json';
        if (! is_file($hub)) {
            $this->markTestSkipped('hub repo not checked out next to this one');
        }
        $this->assertSame(file_get_contents($hub), file_get_contents(__DIR__.'/fixtures/hmac-signing.json'));
    }

    public function test_verify_reasons(): void
    {
        $secret = str_repeat('a', 64);
        $ts = '1757300000';
        $now = 1757300000;
        $sig = Signature::sign($secret, Signature::stringToSign($ts, 'GET', '/api/sitecommand/info?sc_nonce=abcdefgh1', ''));
        $take = fn () => true;
        $this->assertNull(Signature::verify($secret, 'GET', '/api/sitecommand/info?sc_nonce=abcdefgh1', '', $ts, $sig, 'abcdefgh1', $take, $now));
        $this->assertSame('no_secret', Signature::verify('short', 'GET', '/x', '', $ts, $sig, 'abcdefgh1', $take, $now));
        $this->assertSame('missing', Signature::verify($secret, 'GET', '/x', '', $ts, $sig, null, $take, $now));
        $this->assertSame('stale', Signature::verify($secret, 'GET', '/x', '', $ts, $sig, 'abcdefgh1', $take, $now + 301));
        $this->assertSame('bad_signature', Signature::verify($secret, 'GET', '/api/sitecommand/info?sc_nonce=other1234', '', $ts, $sig, 'abcdefgh1', $take, $now));
        $this->assertSame('replay', Signature::verify($secret, 'GET', '/api/sitecommand/info?sc_nonce=abcdefgh1', '', $ts, $sig, 'abcdefgh1', fn () => false, $now));
    }
}
