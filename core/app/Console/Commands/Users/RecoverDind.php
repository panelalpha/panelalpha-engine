<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Recover a wedged Sysbox DinD user container without rebooting the host.
 *
 * Runs host-side /usr/local/sbin/recover-sysbox-dind.sh via nsenter (needs
 * host root). See that script for the FUSE-abort + cgroup.kill procedure.
 */
class RecoverDind extends Command
{
    use ResolvesProject;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:recover-dind', 'users:recover-dind'];

    protected $signature = 'project:dind:recover
        {--project= : DinD username / outer container name} {--username= : Deprecated alias for --project}
        {--all-stuck : Recover every sysbox container that looks stuck}
        {--detect : Only detect stuck DinD containers}
        {--wipe-inner : Wipe ~/docker inner Docker data-root}
        {--recreate : Recreate outer container with docker compose up -d}';

    protected $description = 'Recover wedged Sysbox DinD containers without host reboot (FUSE abort).';

    private const HOST_SCRIPT = '/usr/local/sbin/recover-sysbox-dind.sh';

    public function handle(): int
    {
        $this->foldProjectOption();

        $username = $this->option('username');
        $allStuck = (bool)$this->option('all-stuck');
        $detect = (bool)$this->option('detect');
        $wipeInner = (bool)$this->option('wipe-inner');
        $recreate = (bool)$this->option('recreate');

        if (!$detect && !$username && !$allStuck) {
            $this->error('One of --username=, --all-stuck, or --detect is required.');
            return 1;
        }

        if ($username) {
            $user = User::findByUsername($username);
            if (!$user) {
                $this->error('Invalid username');
                return 1;
            }
            if ($user->getTemplate() !== 'dind') {
                $this->error("User `{$username}` is not a DinD (sysbox) project.");
                return 1;
            }
        }

        $args = [];
        if ($detect) {
            $args[] = '--detect';
        } elseif ($allStuck) {
            $args[] = '--all-stuck';
        } else {
            $args[] = '--username=' . $username;
        }
        if ($wipeInner) {
            $args[] = '--wipe-inner';
        }
        if ($recreate) {
            $args[] = '--recreate';
        }

        $quoted = implode(' ', array_map('escapeshellarg', $args));
        $hostCmd = 'if [ -x ' . escapeshellarg(self::HOST_SCRIPT) . ' ]; then '
            . escapeshellarg(self::HOST_SCRIPT) . ' ' . $quoted
            . '; else echo "Missing ' . self::HOST_SCRIPT
            . ' — run engine scripts/install-sysbox.sh on the host" >&2; exit 127; fi';

        $this->warn('Running host recovery via nsenter (pid 1)...');
        $process = new Process([
            'sudo',
            'nsenter',
            '--target',
            '1',
            '--all',
            'bash',
            '-c',
            $hostCmd,
        ]);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if ($process->getExitCode() !== 0) {
            $this->error('Recovery failed with exit code ' . (string)$process->getExitCode());
            return (int)$process->getExitCode();
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
                $this->warn('Container recovered but rebuild failed: ' . $e->getMessage());
            }
        }

        return 0;
    }
}
