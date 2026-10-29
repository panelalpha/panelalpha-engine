<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Models\Domain;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class CleanupDisconnectedDomains extends Command
{
    use ConfirmsChanges;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:cleanup-disconnected-domains'];

    protected $signature = 'system:domain:cleanup {--force : Skip confirmation}';

    protected $description = 'Remove webserver vhosts for domains no longer in the database (host-wide; project:domain:cleanup does the same per project)';

    public function handle(): int
    {
        try {
            // $webservers = [
            //     'apache',
            //     'nginx',
            //     'nginx-proxy',
            //     'litespeed',
            //     'openlitespeed',
            // ];

            $system = new System;
            $webserver = $system->webserver();
            $webDomains = $webserver->listDomains();
            /** @var Collection<int, Domain> */
            $dbDomains = Domain::get();
            $dbDomainsList = $dbDomains->pluck('domain');
            $unusedDomains = [];
            foreach ($webDomains as $webDomain) {
                if (! ($dbDomainsList->contains($webDomain))) {
                    $unusedDomains[] = $webDomain;
                }
            }

            if (empty($unusedDomains)) {
                $this->info('Did not found any disconnected domains. Nothing to cleanup.');

                return 0;
            }

            if ($this->refusedWithoutConfirmation()) {
                return 1;
            }

            $this->info('Found '.count($unusedDomains).' disconnected domains: ');
            foreach ($unusedDomains as $unusedDomain) {
                $this->info('  '.$unusedDomain);
            }
            if (! $this->option('force') && ! $this->confirm('Continue with cleanup of these domains?')) {
                $this->info('Exiting.');

                return 0;
            }
            $webserver->deleteDomainsConfigs($unusedDomains);
            if (! config('env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS')) {
                $system->webserver()->deleteDomainsLogsDirs($unusedDomains);
            }
            $webserver->reload();

            $this->info('Domains cleaned up.');
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

        return 0;
    }
}
