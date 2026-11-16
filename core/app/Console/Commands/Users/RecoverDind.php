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
                $this->error("Project `{$username}` is not a DinD (sysbox) project.");

                return 1;
            }
        }

        $this->warn('Running host recovery in the host\'s namespaces...');
        $process = $this->recoveryProcess($this->recoveryCommand($username, $detect, $allStuck, $wipeInner, $recreate));
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if ($process->getExitCode() !== 0) {
            $this->error('Recovery failed with exit code '.(string) $process->getExitCode());

            return (int) $process->getExitCode();
        }

        if ($detect || ! $username) {
            return 0;
        }

        if (! self::recovered($process->getOutput(), $username)) {
            $this->sayOptionsSkipped($username, $wipeInner, $recreate);

            return 0;
        }

        if ($recreate) {
            $this->refreshComposeTemplate($username);
        }

        return 0;
    }

    /** @param  list<string>  $argv */
    protected function recoveryProcess(array $argv): Process
    {
        return new Process($argv);
    }

    /**
     * The broker ends a recovery it ran with `done <name>`; an account it found healthy it leaves alone, and a
     * rebuild then would only redeploy it and drop the files written into ~/project since the last deploy.
     */
    private static function recovered(string $output, string $username): bool
    {
        return preg_match('/(?:^|\] )done '.preg_quote($username, '/').' \(/m', $output) === 1;
    }

    private function sayOptionsSkipped(string $username, bool $wipeInner, bool $recreate): void
    {
        $skipped = array_keys(array_filter(['--wipe-inner' => $wipeInner, '--recreate' => $recreate]));
        if ($skipped === []) {
            return;
        }

        $this->info(implode(' and ', $skipped)." skipped: `{$username}` is healthy, so nothing was recreated, wiped or rebuilt.");
    }

    /** Best-effort, after a real recreate: the compose template refreshed so mem_limit and the restart policy match the database. */
    private function refreshComposeTemplate(string $username): void
    {
        try {
            $user = User::findByUsername($username);
            if ($user) {
                $this->info("Rebuilding project `{$username}` config...");
                $this->rebuild($user);
            }
        } catch (\Throwable $e) {
            $this->warn('Container recovered but rebuild failed: '.$e->getMessage());
        }
    }

    protected function rebuild(User $user): void
    {
        $user->project()->rebuildFromSource();
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
