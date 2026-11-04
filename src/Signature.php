<?php

namespace Sitecommand\Laravel;

/**
 * The Sitecommand signing contract (the same one the WordPress and Node
 * connectors use, pinned by the shared fixture in tests/fixtures):
 *
 *   string_to_sign = timestamp \n METHOD \n signed_path \n sha256_hex(raw_body)
 *   signature      = hex(HMAC-SHA256(secret_as_utf8_string, string_to_sign))
 *
 * signed_path is the request path and query exactly as the hub sent it.
 * Requests more than 300 seconds off are refused, and each carries a
 * single-use nonce (`sc_nonce` query parameter). Every answer is signed back:
 * HMAC(secret, nonce \n sha256_hex(body)).
 */
final class Signature
{
    public const HEADER_TIMESTAMP = 'X-Sitecommand-Timestamp';
    public const HEADER_SIGNATURE = 'X-Sitecommand-Signature';
    public const HEADER_RESPONSE_SIGNATURE = 'X-Sitecommand-Response-Signature';
    public const NONCE_PARAM = 'sc_nonce';
    public const MAX_SKEW_SECONDS = 300;

    public static function stringToSign(string $timestamp, string $method, string $signedPath, string $rawBody): string
    {
        return implode("\n", [$timestamp, strtoupper($method), $signedPath, hash('sha256', $rawBody)]);
    }

    public static function sign(string $secret, string $message): string
    {
        return hash_hmac('sha256', $message, $secret);
    }

    public static function responseSignature(string $secret, string $nonce, string $body): string
    {
        return self::sign($secret, $nonce."\n".hash('sha256', $body));
    }

    /**
     * Check one request. Returns null when it is good, else the reason
     * (missing, stale, bad_signature, replay, no_secret). $takeNonce marks a
     * nonce as used and returns false when it was already used.
     *
     * @param  callable(string): bool  $takeNonce
     */
    public static function verify(?string $secret, string $method, string $signedPath, string $rawBody, ?string $timestamp, ?string $signature, ?string $nonce, callable $takeNonce, ?int $now = null): ?string
    {
        if ($secret === null || strlen($secret) < 32) {
            return 'no_secret';
        }
        if (! $timestamp || ! $signature || ! $nonce || ! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $nonce)) {
            return 'missing';
        }
        $now ??= time();
        if (! preg_match('/^\d{9,12}$/', $timestamp) || abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return 'stale';
        }
        $expected = self::sign($secret, self::stringToSign($timestamp, $method, $signedPath, $rawBody));
        if (! preg_match('/^[0-9a-f]{64}$/i', $signature) || ! hash_equals($expected, strtolower($signature))) {
            return 'bad_signature';
        }
        if (! $takeNonce($nonce)) {
            return 'replay';
        }

        return null;
    }
}
