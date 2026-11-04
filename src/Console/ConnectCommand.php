<?php

namespace Sitecommand\Laravel\Console;

use Illuminate\Console\Command;
use Sitecommand\Laravel\SitecommandServiceProvider;

/**
 * Connect this app to Sitecommand with a code: the hub shows a code, you
 * approve it in the dashboard, and the site secret is written into .env.
 * Run it where the app's live .env is (the server), after the code with this
 * package is deployed.
 */
class ConnectCommand extends Command
{
    protected $signature = 'sitecommand:connect
        {--url= : The app\'s public https:// address (default: APP_URL)}
        {--hub= : The Sitecommand hub (default: SITECOMMAND_HUB or https://siteconnector.vercel.app)}
        {--name= : Name shown in Sitecommand (default: APP_NAME)}
        {--no-verify : Do not ask the hub to check the app at the end}';

    protected $description = 'Connect this Laravel app to Sitecommand (writes SITECOMMAND_SECRET into .env)';

    public function handle(): int
    {
        $hub = Hub::url($this->option('hub'));
        $url = self::origin((string) ($this->option('url') ?: config('app.url')));
        if ($url === null) {
            $this->error('Give the app\'s public address: php artisan sitecommand:connect --url=https://example.com (APP_URL is not an https:// address).');

            return self::FAILURE;
        }
        $base = SitecommandServiceProvider::basePath();

        $this->line("Connecting <info>{$url}</info> to <info>{$hub}</info>");
        [$status, $start] = Hub::post($hub.'/api/connect/device', [
            'site_url' => $url,
            'framework' => 'laravel',
            'app_name' => (string) ($this->option('name') ?: config('app.name')),
            'base_path' => $base,
        ]);
        if ($status !== 200 || empty($start['device_code'])) {
            $this->error('The hub could not start the connection: '.($start['message'] ?? "HTTP {$status}"));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Open:  <info>'.$start['verification_uri_complete'].'</info>');
        $this->line('  Code:  <comment>'.$start['user_code'].'</comment>');
        $this->newLine();
        $this->line('Approve it in Sitecommand (sign in if asked). Waiting…');

        $deadline = time() + (int) ($start['expires_in'] ?? 600);
        $interval = max(2, (int) ($start['interval'] ?? 3));
        $approved = null;
        while (time() < $deadline) {
            sleep($interval);
            [, $poll] = Hub::post($hub.'/api/connect/device/poll', ['device_code' => $start['device_code']], 30);
            $s = $poll['status'] ?? 'unknown';
            if ($s === 'approved') {
                $approved = $poll;
                break;
            }
            if ($s === 'slow_down') {
                $interval += 2;
            } elseif ($s !== 'pending') {
                $this->error($s === 'denied' ? 'The request was denied in Sitecommand.' : ($s === 'expired' ? 'The code expired. Run the command again.' : 'The hub no longer knows this request. Run the command again.'));

                return self::FAILURE;
            }
        }
        if ($approved === null || empty($approved['site_secret'])) {
            $this->error('The code expired. Run the command again.');

            return self::FAILURE;
        }

        $values = ['SITECOMMAND_SECRET' => $approved['site_secret'], 'SITECOMMAND_SITE_ID' => $approved['site_id'], 'SITECOMMAND_HUB' => $hub];
        if (! Hub::writeEnv($values)) {
            $this->error('Could not write '.app()->environmentFilePath().'. Add these lines to the app\'s .env yourself:');
            foreach ($values as $k => $v) {
                $this->line("  {$k}={$v}");
            }

            return self::FAILURE;
        }
        $this->info('✓ Connected. Saved SITECOMMAND_SECRET, SITECOMMAND_SITE_ID and SITECOMMAND_HUB in .env');

        if ($this->option('no-verify')) {
            $this->line('Run <info>php artisan sitecommand:verify</info> once the app is deployed with this package.');

            return self::SUCCESS;
        }
        $this->newLine();
        $this->line('Asking the hub to check the app…');
        [, $report] = Hub::verify($hub, (string) $approved['site_id'], (string) $approved['site_secret']);
        if (Hub::printReport($this, $report)) {
            $this->info('✓ Verified. The app shows as connected in Sitecommand.');

            return self::SUCCESS;
        }
        $this->warn('Not verified yet. If this is not the server, deploy the app (and put these values in the server\'s .env), then run php artisan sitecommand:verify there.');

        return self::FAILURE;
    }

    public static function origin(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $parts = parse_url(str_starts_with($input, 'http') ? $input : 'https://'.$input);
        if (! $parts || empty($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $local = in_array($parts['host'], ['localhost', '127.0.0.1'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
