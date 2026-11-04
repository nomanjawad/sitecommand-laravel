<?php

namespace Sitecommand\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Sitecommand\Laravel\Dependencies;
use Sitecommand\Laravel\Errors;
use Sitecommand\Laravel\Health;
use Sitecommand\Laravel\Info;
use Sitecommand\Laravel\Mask;
use Sitecommand\Laravel\Signature;
use Sitecommand\Laravel\Sitecommand;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every connector route. Anything not correctly signed gets a plain 404, so
 * the route does not reveal that Sitecommand is installed. Every answer is
 * signed back with the site secret.
 */
class ConnectorController
{
    public function __construct(private readonly Sitecommand $registry) {}

    public function __invoke(Request $request, ?string $path = null): Response
    {
        $method = strtoupper($request->getMethod());
        $raw = in_array($method, ['GET', 'HEAD'], true) ? '' : (string) $request->getContent();
        $secret = config('sitecommand.secret');
        $secret = is_string($secret) ? trim($secret) : null;
        $nonce = $request->query(Signature::NONCE_PARAM);

        $reason = Signature::verify(
            $secret,
            $method,
            $request->getRequestUri(),
            $raw,
            $request->headers->get(Signature::HEADER_TIMESTAMP),
            $request->headers->get(Signature::HEADER_SIGNATURE),
            is_string($nonce) ? $nonce : null,
            fn (string $n) => $this->takeNonce($n),
        );
        if ($reason !== null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        $route = trim((string) $path, '/');
        $status = 200;
        try {
            if ($method === 'GET' && $route === 'info') {
                $payload = Info::build($this->registry);
            } elseif ($method === 'GET' && $route === 'health') {
                $payload = (new Health($this->registry))->run();
            } elseif ($method === 'GET' && $route === 'errors') {
                $payload = Errors::recent();
            } elseif ($method === 'GET' && $route === 'deps') {
                $payload = Dependencies::read();
            } elseif ($method === 'GET' && $route === 'actions') {
                $payload = ['ok' => true, 'actions' => $this->listActions()];
            } elseif ($method === 'POST' && str_starts_with($route, 'actions/')) {
                [$status, $payload] = $this->runAction(rawurldecode(substr($route, strlen('actions/'))), $raw);
            } else {
                $status = 404;
                $payload = ['ok' => false, 'code' => 'unknown_route', 'message' => "No connector route {$method} /{$route}."];
            }
        } catch (Throwable $e) {
            report($e);
            $status = 500;
            $payload = ['ok' => false, 'code' => 'connector_error', 'message' => Mask::text($e->getMessage(), 300)];
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return new Response($body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            Signature::HEADER_RESPONSE_SIGNATURE => Signature::responseSignature((string) $secret, (string) $nonce, $body),
        ]);
    }

    /** Single-use nonces, kept for twice the clock window in the app's cache. */
    private function takeNonce(string $nonce): bool
    {
        $store = config('sitecommand.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null)->add('sitecommand:nonce:'.hash('sha256', $nonce), 1, Signature::MAX_SKEW_SECONDS * 2);
    }

    /** @return list<array{name: string, description: string, changes: bool, input: object}> */
    private function listActions(): array
    {
        $out = [];
        foreach ($this->registry->actions() as $name => $a) {
            $out[] = ['name' => $name, 'description' => $a['description'], 'changes' => $a['changes'], 'input' => (object) $a['input']];
        }

        return $out;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function runAction(string $name, string $raw): array
    {
        $action = $this->registry->actions()[$name] ?? null;
        if ($action === null) {
            return [404, ['ok' => false, 'code' => 'unknown_action', 'message' => "No action named \"{$name}\"."]];
        }
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (! is_array($body)) {
            return [400, ['ok' => false, 'code' => 'bad_json', 'message' => 'The body is not JSON.']];
        }
        [$ok, $input] = Sitecommand::validateInput($action['input'], $body['input'] ?? []);
        if (! $ok) {
            return [400, ['ok' => false, 'code' => 'bad_input', 'message' => $input]];
        }
        $started = microtime(true);
        @set_time_limit($action['timeout'] + 5);
        try {
            $result = ($action['run'])($input);

            return [200, ['ok' => true, 'action' => $name, 'ms' => (int) round((microtime(true) - $started) * 1000), 'result' => self::limitJson($result, 100000)]];
        } catch (Throwable $e) {
            report($e);

            return [500, ['ok' => false, 'code' => 'action_failed', 'action' => $name, 'ms' => (int) round((microtime(true) - $started) * 1000), 'message' => Mask::text($e->getMessage(), 500)]];
        }
    }

    private static function limitJson(mixed $value, int $max): mixed
    {
        if ($value instanceof \JsonSerializable || $value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value instanceof \Illuminate\Contracts\Support\Arrayable ? $value->toArray() : $value->jsonSerialize();
        }
        $s = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($s === false) {
            return is_scalar($value) ? $value : null;
        }

        return strlen($s) <= $max ? json_decode($s, true) : ['truncated' => true, 'preview' => mb_strcut($s, 0, $max)];
    }
}
