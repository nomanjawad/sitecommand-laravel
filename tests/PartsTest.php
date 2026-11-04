<?php

namespace Sitecommand\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Sitecommand\Laravel\Console\ConnectCommand;
use Sitecommand\Laravel\Console\Hub;
use Sitecommand\Laravel\Dependencies;
use Sitecommand\Laravel\Errors;
use Sitecommand\Laravel\Mask;
use Sitecommand\Laravel\Sitecommand;

class PartsTest extends TestCase
{
    public function test_errors_are_parsed_grouped_and_masked(): void
    {
        $log = implode("\n", [
            '[2026-11-03 10:00:00] production.ERROR: Undefined variable $x {"userId":1,"exception":"[object] (ErrorException(code: 0): Undefined variable $x at /home/site/app/Http/Controllers/Home.php:12)',
            '[stacktrace]',
            '#0 /home/site/vendor/laravel/framework/src/Foundation/Bootstrap/HandleExceptions.php(258): handleError()',
            '#1 {main}',
            '"} ',
            '[2026-11-03 10:05:00] production.INFO: all good',
            '[2026-11-03 11:00:00] production.ERROR: Undefined variable $x {"exception":"[object] (ErrorException(code: 0): Undefined variable $x at /home/site/app/Http/Controllers/Home.php:12)',
            '[2026-11-03 12:00:00] production.CRITICAL: Mail failed for someone@example.com password=hunter22',
        ]);
        $errors = Errors::parse($log, '/home/site/');
        $this->assertCount(2, $errors);
        $this->assertSame('critical', $errors[0]['level']);
        $this->assertStringNotContainsString('someone@example.com', $errors[0]['message']);
        $this->assertStringNotContainsString('hunter22', $errors[0]['message']);
        $this->assertSame('ErrorException', $errors[1]['name']);
        $this->assertSame('app/Http/Controllers/Home.php:12', $errors[1]['where']);
        $this->assertSame(2, $errors[1]['count']);
        $this->assertSame('2026-11-03T11:00:00Z', $errors[1]['last']);
        $this->assertStringContainsString('#0 vendor/laravel', $errors[1]['stack']);
    }

    public function test_error_class_and_path_with_spaces_from_json_context(): void
    {
        $log = '[2026-11-04 07:05:28] local.ERROR: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1) {"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 2002): SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1) at /Users/me/Working Files/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:838)';
        $e = Errors::parse($log, '/Users/me/Working Files/app/')[0];
        $this->assertSame('Illuminate\\Database\\QueryException', $e['name']);
        $this->assertSame('vendor/laravel/framework/src/Illuminate/Database/Connection.php:838', $e['where']);
        $this->assertSame('SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1)', $e['message']);
    }

    public function test_composer_packages(): void
    {
        $deps = Dependencies::fromComposerPackages([
            ['name' => 'laravel/framework', 'version' => 'v13.9.0'],
            ['name' => 'brick/math', 'version' => '0.14.1'],
            ['name' => 'php', 'version' => '8.4'],
        ], ['laravel/framework' => true]);
        $this->assertSame([
            ['name' => 'laravel/framework', 'version' => '13.9.0', 'direct' => true, 'ecosystem' => 'packagist'],
            ['name' => 'brick/math', 'version' => '0.14.1', 'direct' => false, 'ecosystem' => 'packagist'],
        ], $deps);
    }

    public function test_npm_lockfiles(): void
    {
        $lock = Dependencies::fromPackageLock(['packages' => ['' => ['version' => '1.0.0'], 'node_modules/vue' => ['version' => '3.5.0'], 'node_modules/a/node_modules/vue' => ['version' => '2.0.0'], 'packages/x' => ['version' => '1.0.0', 'link' => true]]], ['vue' => true]);
        $this->assertSame([['name' => 'vue', 'version' => '3.5.0', 'direct' => true, 'ecosystem' => 'npm'], ['name' => 'vue', 'version' => '2.0.0', 'direct' => false, 'ecosystem' => 'npm']], $lock);

        $pnpm = "lockfileVersion: '9.0'\n\nimporters:\n  .:\n    dependencies:\n      vue:\n        version: 3.5.34\n\npackages:\n\n  '@inertiajs/vue3@3.1.1':\n    resolution: {integrity: sha512-x}\n\n  vue@3.5.34(typescript@6.0.3):\n    resolution: {integrity: sha512-y}\n\nsnapshots:\n\n  vue@3.5.34(typescript@6.0.3):\n    dependencies: {}\n";
        $deps = Dependencies::fromPnpmLock($pnpm, ['vue' => true]);
        $this->assertSame([['name' => '@inertiajs/vue3', 'version' => '3.1.1', 'direct' => false, 'ecosystem' => 'npm'], ['name' => 'vue', 'version' => '3.5.34', 'direct' => true, 'ecosystem' => 'npm']], $deps);
    }

    public function test_mask(): void
    {
        $out = Mask::text('mysql://root:secret@db/x Bearer abcdefghijkl APP_KEY=base64:'.str_repeat('A', 44).' sk_live_abcdefghijklmnop blob '.str_repeat('aB3/', 14));
        $this->assertStringContainsString('vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php', Mask::text('at vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php'));
        foreach (['root:secret', 'abcdefghijkl', 'AAAAAAAA', 'sk_live_', 'aB3/aB3/aB3/'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
    }

    public function test_input_validation(): void
    {
        $spec = ['limit' => 'number?', 'q' => 'string', 'all' => 'boolean?'];
        $this->assertSame([true, ['limit' => 5, 'q' => 'x']], Sitecommand::validateInput($spec, ['q' => 'x', 'limit' => 5]));
        $this->assertFalse(Sitecommand::validateInput($spec, [])[0]);
        $this->assertFalse(Sitecommand::validateInput($spec, ['q' => 1])[0]);
        $this->assertFalse(Sitecommand::validateInput($spec, ['q' => 'x', 'zzz' => 1])[0]);
        $this->assertFalse(Sitecommand::validateInput($spec, ['a', 'b'])[0]);
        $this->assertTrue(Sitecommand::validateInput([], [])[0]);
    }

    public function test_env_upsert_and_origin(): void
    {
        $env = "APP_NAME=X\nSITECOMMAND_SECRET=old\n";
        $out = Hub::upsertEnv($env, ['SITECOMMAND_SECRET' => 'new$1', 'SITECOMMAND_SITE_ID' => 'abc']);
        $this->assertStringContainsString("SITECOMMAND_SECRET=new\$1\n", $out);
        $this->assertSame(1, substr_count($out, 'SITECOMMAND_SECRET='));
        $this->assertStringContainsString("SITECOMMAND_SITE_ID=abc\n", $out);
        $this->assertStringStartsWith('APP_NAME=X', $out);

        $this->assertSame('https://example.com', ConnectCommand::origin('https://Example.com/path?x=1'));
        $this->assertSame('https://example.com', ConnectCommand::origin('example.com'));
        $this->assertSame('http://localhost:8000', ConnectCommand::origin('http://localhost:8000'));
        $this->assertNull(ConnectCommand::origin('http://example.com'));
        $this->assertNull(ConnectCommand::origin('https://u:p@example.com'));
    }
}
