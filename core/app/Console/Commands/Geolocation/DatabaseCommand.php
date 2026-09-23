<?php

namespace App\Console\Commands\Geolocation;

use Illuminate\Console\Command;
use RuntimeException;

use App\Integrations\GeoLocation\AcceptsDatabaseTerms;
use App\Integrations\GeoLocation\DbIp;
use App\Integrations\GeoLocation\GeoLocation;
use App\Integrations\GeoLocation\TermsRequiredException;

class DatabaseCommand extends Command
{
    private const array ALLOWED_ACTIONS = ['update'];

    protected $signature = 'geolocation:database
                            {action : update}
                            {--accept-terms : Accept the driver license terms without a prompt}
                            {--force : Download even when this month is already stored}';

    protected $description = 'Fetch or refresh the local City geolocation database';

    public function handle(GeoLocation $geoLocation): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            $this->error('Unknown action. Allowed actions: '.implode(', ', self::ALLOWED_ACTIONS));

            return self::FAILURE;
        }

        try {
            $this->runUpdate($geoLocation);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printAttribution($geoLocation);

        return self::SUCCESS;
    }

    private function printAttribution(GeoLocation $geoLocation): void
    {
        if ($geoLocation instanceof DbIp) {
            $this->info(DbIp::ATTRIBUTION);
        }
    }

    private function runUpdate(GeoLocation $geoLocation): void
    {
        $force = (bool) $this->option('force');
        try {
            $geoLocation->updateDatabase($force);

            return;
        } catch (TermsRequiredException $e) {
            $this->acceptTermsOrFail($geoLocation, $e);
        }

        $geoLocation->updateDatabase($force);
    }

    private function acceptTermsOrFail(GeoLocation $geoLocation, TermsRequiredException $e): void
    {
        if (! $this->option('accept-terms')) {
            if (! $this->canPromptTerms()) {
                throw new RuntimeException($e->getMessage());
            }
            if (! $this->confirm($e->getMessage()."\nAccept these terms and download the database?")) {
                throw new RuntimeException('Terms were not accepted. Geolocation database was not updated.');
            }
        }

        if ($geoLocation instanceof AcceptsDatabaseTerms) {
            $geoLocation->acceptTerms();
        }
    }

    private function canPromptTerms(): bool
    {
        return $this->input->isInteractive() && @stream_isatty(STDIN);
    }
}
