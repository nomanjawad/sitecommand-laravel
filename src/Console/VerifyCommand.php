<?php

namespace Sitecommand\Laravel\Console;

use Illuminate\Console\Command;

/** Ask the hub to run Verify against the deployed app (the same check as the dashboard button). */
class VerifyCommand extends Command
{
    protected $signature = 'sitecommand:verify {--hub= : The Sitecommand hub (default: SITECOMMAND_HUB)}';

    protected $description = 'Ask Sitecommand to check this app\'s connector';

    public function handle(): int
    {
        $secret = (string) \Sitecommand\Laravel\Settings::secret();
        $siteId = (string) \Sitecommand\Laravel\Settings::siteId();
        if (strlen($secret) < 32 || ! preg_match('/^[0-9a-f-]{36}$/i', $siteId)) {
            $this->error('SITECOMMAND_SECRET and SITECOMMAND_SITE_ID are not set. Run php artisan sitecommand:connect first (or press Verify in the dashboard).');

            return self::FAILURE;
        }
        [$status, $report] = Hub::verify(Hub::url($this->option('hub')), $siteId, $secret);
        if ($status === 404) {
            $this->error('The hub does not know this site id with this secret. Connect again: php artisan sitecommand:connect');

            return self::FAILURE;
        }
        if (Hub::printReport($this, $report)) {
            $this->info('✓ Verified.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
