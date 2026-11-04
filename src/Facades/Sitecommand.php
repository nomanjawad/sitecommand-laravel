<?php

namespace Sitecommand\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Sitecommand\Laravel\Sitecommand health(string $name, callable $check)
 * @method static \Sitecommand\Laravel\Sitecommand action(string $name, string $description, callable $run, bool $changes = false, array $input = [], int $timeout = 25)
 *
 * @see \Sitecommand\Laravel\Sitecommand
 */
class Sitecommand extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sitecommand\Laravel\Sitecommand::class;
    }
}
