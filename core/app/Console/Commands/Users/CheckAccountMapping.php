<?php

namespace App\Console\Commands\Users;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Models\User;
use App\System;
use App\System\AccountContainers;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountMapping;
use App\System\Project\Dind\AccountRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recreate running account containers sysbox no longer maps ({@see AccountMapping}):
 * a sysbox restart or a host crash leaves them broken with nobody deploying to notice.
 */
class CheckAccountMapping extends Command
{
    protected $signature = 'project:mapping:check
        {--dry-run : Only report the accounts that would be recreated}';

    protected $description = 'Recreate running account containers whose image sysbox no longer maps';

    public function handle(): int
    {
        $system = app(System::class);
        $dryRun = (bool) $this->option('dry-run');
        $running = (new AccountContainers($system))->runningRuntimes();
        if ($running === null) {
            $this->warn('Could not list the running account containers.');

            return self::FAILURE;
        }

        $checked = 0;
        $unmapped = 0;
        $recreated = 0;
        foreach ($running as $name => $runtime) {
            // A privileged account has no mapping to lose.
            $project = $runtime === AccountRuntime::SYSBOX ? $this->project($system, $name) : null;
            if ($project === null) {
                continue;
            }
            $checked++;
            $mapping = new AccountMapping($project);
            if ($mapping->isUnmapped() !== true) {
                continue;
            }
            $unmapped++;
            if ($dryRun) {
                $this->line("{$name}: image unmapped, would recreate the account container");
                continue;
            }
            try {
                $lock = DeployLock::acquireFor($name);
            } catch (\Throwable) {
                $this->line("{$name}: image unmapped, left to the deploy or sweep holding its deploy lock");
                continue;
            }
            try {
                $mapping->recreate();
                $recreated++;
                $this->line("{$name}: image unmapped, recreated the account container");
            } catch (\Throwable $e) {
                $this->error("{$name}: " . $e->getMessage());
                Log::error("project:mapping:check: {$name}: " . $e->getMessage());
            } finally {
                $lock->release();
            }
        }

        $this->info("Checked {$checked} running account(s): {$unmapped} unmapped, {$recreated} recreated.");

        return self::SUCCESS;
    }

    private function project(System $system, string $username): ?Dind
    {
        $user = User::findByUsername($username);
        $runtime = $user?->project($system)->runtime();

        return $runtime instanceof Dind ? $runtime : null;
    }
}
