<?php

namespace App\Console\Commands\Stats;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\System;

class StatsUpdateCommand extends Command
{
    protected $signature = 'stats:update
                            {--project= : Limit to this project username}
                            {--domain= : Limit to this domain name}';

    protected $description = 'Ingest host access logs into statistics (AWStats)';

    public function handle(System $system, Statistics $stats): int
    {
        $lock = fopen($this->lockPath(), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->warn('stats:update is already running.');
            if (is_resource($lock)) {
                fclose($lock);
            }

            return self::SUCCESS;
        }

        try {
            $webserver = $system->webserver()->getCurrentWebserver();
            $logsRoot = $system->engineDirPath() . '/webserver-logs/' . $webserver;
            foreach ($this->candidateDomains() as $domain) {
                $stats->ingestDomain(
                    $domain->domain,
                    $logsRoot . '/' . $domain->domain,
                    $domain->getAliases(),
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Domain>
     */
    protected function candidateDomains(): Collection
    {
        $query = Domain::query()->with('user');
        $project = $this->option('project');
        if (is_string($project) && $project !== '') {
            $query->whereHas('user', static function (Builder $q) use ($project): void {
                $q->where('username', $project);
            });
        }
        $domain = $this->option('domain');
        if (is_string($domain) && $domain !== '') {
            $query->where('domain', $domain);
        }

        return $query->get();
    }

    private function lockPath(): string
    {
        $dir = storage_path('app');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/stats-update.lock';
    }
}
