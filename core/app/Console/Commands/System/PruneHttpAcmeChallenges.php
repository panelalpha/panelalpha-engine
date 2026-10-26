<?php

namespace App\Console\Commands\System;

use App\Lib\HttpAcmeChallengeStore;
use App\Models\Domain;
use App\System;
use Illuminate\Console\Command;

class PruneHttpAcmeChallenges extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['acme-challenges:prune'];

    protected $signature = 'acme:challenge:prune {--ttl-hours= : Override TTL in hours (default 24)}';

    protected $description = 'Prune expired HTTP-01 ACME challenge files and rebuild vhosts when directories become empty';

    public function handle(): int
    {
        $ttlHours = (int) ($this->option('ttl-hours') ?: env('HTTP_ACME_CHALLENGE_TTL_HOURS', HttpAcmeChallengeStore::DEFAULT_TTL_HOURS));
        if ($ttlHours < 1) {
            $this->warn('TTL hours must be >= 1, skipping.');

            return 0;
        }

        $this->info("Pruning HTTP ACME challenges older than {$ttlHours}h...");

        $system = new System;
        $store = new HttpAcmeChallengeStore($system);
        $domainsToRebuild = $store->prune($ttlHours);

        foreach ($domainsToRebuild as $domainName) {
            $domain = Domain::findByName($domainName);
            if (! $domain) {
                continue;
            }
            $this->info("Rebuilding vhost for {$domainName} (ACME alias disabled).");
            $domain->projectDomain()->rebuild();
        }

        if ($domainsToRebuild !== []) {
            $system->webserver()->reload();
        }

        $this->info('Pruned '.count($domainsToRebuild).' domain(s) that no longer have challenges.');

        return 0;
    }
}
