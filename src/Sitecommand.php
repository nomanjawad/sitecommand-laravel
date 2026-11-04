<?php

namespace Sitecommand\Laravel;

use Closure;
use InvalidArgumentException;

/**
 * What this app exposes to Sitecommand beyond the built-in reads: health
 * checks and named actions, registered in code (usually in
 * AppServiceProvider::boot()). Nothing else can run.
 *
 *   Sitecommand::health('payments', fn () => Payments::ping());
 *   Sitecommand::action('clear-page-cache', 'Clear the page cache', fn () => ResponseCache::clear(), changes: true);
 */
class Sitecommand
{
    public const VERSION = '0.1.0';

    /** @var array<string, Closure> */
    private array $health = [];

    /** @var array<string, array{description: string, changes: bool, input: array<string, string>, timeout: int, run: Closure}> */
    private array $actions = [];

    /**
     * A health check: return true/false, nothing (= ok), or an array like
     * ['ok' => false, 'message' => '…']. Throwing counts as failing.
     */
    public function health(string $name, callable $check): static
    {
        $this->assertName($name);
        $this->health[$name] = Closure::fromCallable($check);

        return $this;
    }

    /**
     * A named action Sitecommand (and the AI through it) may run.
     *
     * @param  bool  $changes  true when it changes data (the hub then needs Full scope on the site)
     * @param  array<string, string>  $input  field => "string" | "number" | "boolean", with "?" for optional
     * @param  int  $timeout  seconds (1–55)
     */
    public function action(string $name, string $description, callable $run, bool $changes = false, array $input = [], int $timeout = 25): static
    {
        $this->assertName($name);
        foreach ($input as $field => $type) {
            if (! is_string($field) || ! preg_match('/^(string|number|boolean)\??$/', (string) $type)) {
                throw new InvalidArgumentException("Sitecommand action \"{$name}\": field \"{$field}\" must be string, number or boolean (with ? for optional).");
            }
        }
        $this->actions[$name] = [
            'description' => $description,
            'changes' => $changes,
            'input' => $input,
            'timeout' => max(1, min(55, $timeout)),
            'run' => Closure::fromCallable($run),
        ];

        return $this;
    }

    /** @return array<string, Closure> */
    public function healthChecks(): array
    {
        return $this->health;
    }

    /** @return array<string, array{description: string, changes: bool, input: array<string, string>, timeout: int, run: Closure}> */
    public function actions(): array
    {
        return $this->actions;
    }

    private function assertName(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Sitecommand: \"{$name}\" is not a valid name (letters, digits, - _ . :, up to 64).");
        }
    }

    /**
     * Check an action's input against its spec. Unknown fields are refused.
     *
     * @param  array<string, string>  $spec
     * @return array{0: true, 1: array<string, mixed>}|array{0: false, 1: string}
     */
    public static function validateInput(array $spec, mixed $input): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            return [false, 'input must be an object.'];
        }
        foreach (array_keys($input) as $key) {
            if (! array_key_exists($key, $spec)) {
                return [false, "Unknown field \"{$key}\"."];
            }
        }
        $value = [];
        foreach ($spec as $key => $typeSpec) {
            $optional = str_ends_with($typeSpec, '?');
            $type = rtrim($typeSpec, '?');
            $v = $input[$key] ?? null;
            if ($v === null) {
                if (! $optional) {
                    return [false, "\"{$key}\" is required."];
                }

                continue;
            }
            $okType = match ($type) {
                'string' => is_string($v),
                'number' => (is_int($v) || is_float($v)) && is_finite((float) $v),
                'boolean' => is_bool($v),
                default => false,
            };
            if (! $okType) {
                return [false, "\"{$key}\" must be a {$type}."];
            }
            if ($type === 'string' && mb_strlen($v) > 10000) {
                return [false, "\"{$key}\" is too long."];
            }
            $value[$key] = $v;
        }

        return [true, $value];
    }
}
