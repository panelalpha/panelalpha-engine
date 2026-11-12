<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\System\Privileged;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Recover a wedged Sysbox DinD user container without rebooting the host.
 *
 * Asks the root broker to run the recovery in the host's namespaces against the host's own Docker daemon.
 */
class RecoverDind extends Command
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:recover-dind', 'users:recover-dind'];

    protected $signature = 'project:dind:recover
        {--project= : DinD username / outer container name}
        {--all-stuck : Recover every sysbox container that looks stuck}
        {--detect : Only detect stuck DinD containers}
        {--wipe-inner : Wipe ~/docker inner Docker data-root}
        {--recreate : Recreate outer container with docker compose up -d}';

    protected $description = 'Recover wedged Sysbox DinD containers without host reboot (FUSE abort).';

    public function handle(): int
    {
        $username = $this->option('project');
        $allStuck = (bool) $this->option('all-stuck');
        $detect = (bool) $this->option('detect');
        $wipeInner = (bool) $this->option('wipe-inner');
        $recreate = (bool) $this->option('recreate');

        if (! $detect && ! $username && ! $allStuck) {
            $this->error('One of --project=, --all-stuck, or --detect is required.');

            return 1;
        }

        if ($username) {
            $user = User::findByUsername($username);
            if (! $user) {
                $this->error('Invalid username');

                return 1;
            }
            if ($user->getTemplate() !== 'dind') {
                $this->error("User `{$username}` is not a DinD (sysbox) project.");

                return 1;
            }
        }

        $this->warn('Running host recovery in the host\'s namespaces...');
        $process = new Process($this->recoveryCommand($username, $detect, $allStuck, $wipeInner, $recreate));
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if ($process->getExitCode() !== 0) {
            $this->error('Recovery failed with exit code '.(string) $process->getExitCode());

            return (int) $process->getExitCode();
        }

        // Best-effort: refresh compose template so mem_limit / restart policy match DB.
        if ($recreate && $username) {
            try {
                $user = User::findByUsername($username);
                if ($user) {
                    $this->info("Rebuilding user `{$username}` project config...");
                    $project = $user->project();
                    $project->rebuildFromSource();
                }
            } catch (\Throwable $e) {
                $this->warn('Container recovered but rebuild failed: '.$e->getMessage());
            }
        }

        return 0;
    }

    /** @return list<string> */
    private function recoveryCommand(?string $username, bool $detect, bool $allStuck, bool $wipeInner, bool $recreate): array
    {
        if ($detect) {
            return Privileged::host()->detectStuckDind();
        }
        if ($allStuck) {
            return Privileged::host()->recoverStuckDind($wipeInner, $recreate);
        }

        return Privileged::host()->recoverDindAccount((string) $username, $wipeInner, $recreate);
    }
}
