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
 * Recreate account containers sysbox no longer maps ({@see AccountMapping}), running
 * or stopped by a start that failed on it: a sysbox restart or a host crash leaves
 * them broken with nobody deploying to notice.
 */
class CheckAccountMapping extends Command
{
    protected $signature = 'project:mapping:check
        {--dry-run : Only report the accounts that would be recreated}';

    protected $description = 'Recreate account containers whose image sysbox no longer maps';

    public function handle(): int
    {
        $system = app(System::class);
        $dryRun = (bool) $this->option('dry-run');
        $containers = new AccountContainers($system);
        $running = $containers->runningRuntimes();
        if ($running === null) {
            $this->warn('Could not list the running account containers.');

            return self::FAILURE;
        }

        [$checked, $unmapped, $recreatedRunning] = $this->checkRunning($system, $running, $dryRun);

        $failedStarts = $containers->failedStarts();
        if ($failedStarts === null) {
            $this->warn('Could not list the stopped account containers.');
        }
        [$refused, $recreatedStopped] = $this->checkFailedStarts($system, $failedStarts ?? [], $dryRun);

        $recreated = $recreatedRunning + $recreatedStopped;
        $this->info("Checked {$checked} running account(s): {$unmapped} unmapped, {$refused} stopped by a failed start, {$recreated} recreated.");

        return $failedStarts === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Recreate the running sysbox accounts whose image is unmapped; a privileged
     * account has no mapping to lose.
     *
     * @param array<string, string> $running runtime by account name
     * @return array{0: int, 1: int, 2: int} checked, unmapped, recreated
     */
    private function checkRunning(System $system, array $running, bool $dryRun): array
    {
        $checked = 0;
        $unmapped = 0;
        $recreated = 0;
        foreach ($running as $name => $runtime) {
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
            $recreated += $this->recreate($name, $mapping, 'image unmapped', $dryRun, reprobe: true) ? 1 : 0;
        }

        return [$checked, $unmapped, $recreated];
    }

    /**
     * Recreate the sysbox accounts stopped by a start that failed on an
     * unmapped image: only a new container starts.
     *
     * @param array<string, array{runtime: string, error: string}> $failedStarts
     * @return array{0: int, 1: int} refused, recreated
     */
    private function checkFailedStarts(System $system, array $failedStarts, bool $dryRun): array
    {
        $refused = 0;
        $recreated = 0;
        foreach ($failedStarts as $name => $start) {
            if ($start['runtime'] !== AccountRuntime::SYSBOX || !AccountMapping::isStartRefusal($start['error'])) {
                continue;
            }
            $project = $this->project($system, $name);
            if ($project === null) {
                continue;
            }
            $refused++;
            $recreated += $this->recreate($name, new AccountMapping($project), 'start failed on an unmapped image', $dryRun) ? 1 : 0;
        }

        return [$refused, $recreated];
    }

    private function recreate(string $name, AccountMapping $mapping, string $why, bool $dryRun, bool $reprobe = false): bool
    {
        if ($dryRun) {
            $this->line("{$name}: {$why}, would recreate the account container");

            return false;
        }
        $lock = $this->lockForRecreate($name, $why);
        if ($lock === null) {
            return false;
        }
        try {
            return $this->recreateUnderLock($name, $mapping, $why, $reprobe);
        } finally {
            $lock->release();
        }
    }

    private function lockForRecreate(string $name, string $why): ?DeployLock
    {
        try {
            return DeployLock::acquireFor($name);
        } catch (\Throwable) {
            $this->line("{$name}: {$why}, left to the deploy or sweep holding its deploy lock");

            return null;
        }
    }

    /** A running account is asked again under the lock: another caller may have recreated it first. */
    private function recreateUnderLock(string $name, AccountMapping $mapping, string $why, bool $reprobe): bool
    {
        if ($reprobe && $mapping->isUnmapped() !== true) {
            $this->line("{$name}: {$why}, mapped again before the sweep reached it");

            return false;
        }
        try {
            $mapping->recreate();
        } catch (\Throwable $e) {
            $this->error("{$name}: " . $e->getMessage());
            Log::error("project:mapping:check: {$name}: " . $e->getMessage());

            return false;
        }
        $this->line("{$name}: {$why}, recreated the account container");

        return true;
    }

    private function project(System $system, string $username): ?Dind
    {
        $user = User::findByUsername($username);
        $runtime = $user?->project($system)->runtime();

        return $runtime instanceof Dind ? $runtime : null;
    }
}
