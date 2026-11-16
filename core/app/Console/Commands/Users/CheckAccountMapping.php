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

    protected $description = 'Recreate account containers sysbox no longer maps or serves';

    /**
     * sysbox's start times for the sweep running, probed at its first sysbox account; a command object can run more
     * than one sweep.
     *
     * @var array{'sysbox-mgr': ?int, 'sysbox-fs': ?int}|null
     */
    private ?array $sysboxStarts = null;

    public function handle(): int
    {
        $this->sysboxStarts = null;
        $system = app(System::class);
        $dryRun = (bool) $this->option('dry-run');
        $containers = new AccountContainers($system);
        $running = $containers->running();
        if ($running === null) {
            $this->warn('Could not list the running account containers.');

            return self::FAILURE;
        }

        [$checked, $toRecreate, $recreatedRunning] = $this->checkRunning($system, $running, $dryRun);

        $failedStarts = $containers->failedStarts();
        if ($failedStarts === null) {
            $this->warn('Could not list the stopped account containers.');
        }
        [$refused, $recreatedStopped] = $this->checkFailedStarts($system, $failedStarts ?? [], $dryRun);

        $recreated = $recreatedRunning + $recreatedStopped;
        $this->info("Checked {$checked} running account(s): {$toRecreate} to recreate, {$refused} stopped by a failed start, {$recreated} recreated.");

        return $failedStarts === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Recreate the running sysbox accounts {@see AccountMapping} counts as unmapped; a privileged
     * account has no mapping to lose. Each is checked with the start time its listing read.
     *
     * @param  array<string, array{runtime: string, startedAt: string}>  $running  by account name
     * @return array{0: int, 1: int, 2: int} checked, to recreate, recreated
     */
    private function checkRunning(System $system, array $running, bool $dryRun): array
    {
        $checked = 0;
        $toRecreate = 0;
        $recreated = 0;
        foreach ($running as $name => $container) {
            $project = $container['runtime'] === AccountRuntime::SYSBOX ? $this->project($system, $name) : null;
            if ($project === null) {
                continue;
            }
            $checked++;
            $mapping = $this->mapping($system, $project);
            if ($mapping->isUnmapped($container['startedAt']) !== true) {
                continue;
            }
            $toRecreate++;
            $recreated += $this->recreate($name, $mapping, $dryRun, reprobe: true) ? 1 : 0;
        }

        return [$checked, $toRecreate, $recreated];
    }

    /**
     * Recreate the sysbox accounts stopped by a start that failed on an
     * unmapped image: only a new container starts.
     *
     * @param  array<string, array{runtime: string, error: string}>  $failedStarts
     * @return array{0: int, 1: int} refused, recreated
     */
    private function checkFailedStarts(System $system, array $failedStarts, bool $dryRun): array
    {
        $refused = 0;
        $recreated = 0;
        foreach ($failedStarts as $name => $start) {
            if ($start['runtime'] !== AccountRuntime::SYSBOX || ! AccountMapping::isStartRefusal($start['error'])) {
                continue;
            }
            $project = $this->project($system, $name);
            if ($project === null) {
                continue;
            }
            $refused++;
            $recreated += $this->recreate($name, $this->mapping($system, $project)->afterRefusedStart(), $dryRun) ? 1 : 0;
        }

        return [$refused, $recreated];
    }

    /** Each line names what the account's check saw, so a sysbox-fs restart is not reported as an unmapped image. */
    private function recreate(string $name, AccountMapping $mapping, bool $dryRun, bool $reprobe = false): bool
    {
        if ($dryRun) {
            $this->line("{$name}: {$mapping->reason()}, would recreate the account container");

            return false;
        }
        $lock = $this->lockForRecreate($name, $mapping);
        if ($lock === null) {
            return false;
        }
        try {
            return $this->recreateUnderLock($name, $mapping, $reprobe);
        } finally {
            $lock->release();
        }
    }

    private function lockForRecreate(string $name, AccountMapping $mapping): ?DeployLock
    {
        try {
            return DeployLock::acquireFor($name);
        } catch (\Throwable) {
            $this->line("{$name}: {$mapping->reason()}, left to the deploy or sweep holding its deploy lock");

            return null;
        }
    }

    /** A running account is asked again under the lock: another caller may have recreated it first. */
    private function recreateUnderLock(string $name, AccountMapping $mapping, bool $reprobe): bool
    {
        if ($reprobe && ! $this->stillUnmapped($name, $mapping)) {
            return false;
        }
        try {
            $mapping->recreate();
        } catch (\Throwable $e) {
            $this->error("{$name}: ".$e->getMessage());
            Log::error("project:mapping:check: {$name}: ".$e->getMessage());

            return false;
        }
        $this->line("{$name}: {$mapping->reason()}, recreated the account container");

        return true;
    }

    /** One the second check cannot reach is not called repaired: it may be down or booting, so the next sweep asks it. */
    private function stillUnmapped(string $name, AccountMapping $mapping): bool
    {
        $unmapped = $mapping->isUnmapped();
        if ($unmapped === null) {
            $this->line("{$name}: {$mapping->reason()}, could not be checked again, left to the next sweep");
        } elseif ($unmapped === false) {
            $this->line("{$name}: {$mapping->reason()}, repaired before the sweep reached it");
        }

        return $unmapped === true;
    }

    /** Every account is checked against the sysbox start times probed once for the sweep; unknown for all when that probe could not answer. */
    private function mapping(System $system, Dind $project): AccountMapping
    {
        $this->sysboxStarts ??= AccountMapping::probeSysbox($system);

        return new AccountMapping($project, $this->sysboxStarts);
    }

    private function project(System $system, string $username): ?Dind
    {
        $user = User::findByUsername($username);
        $runtime = $user?->project($system)->runtime();

        return $runtime instanceof Dind ? $runtime : null;
    }
}
