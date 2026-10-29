<?php

namespace App\Console\Commands\Deploy;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Console\Command;

class DeployLogsPruneCommand extends Command
{
    use ResolvesProject;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['deploy-logs:prune'];

    protected $signature = 'deploy:log:prune
                            {--project= : Limit prune to a single engine username} {--user= : Deprecated alias for --project}
                            {--keep= : How many newest .log files to keep per user (default DEPLOY_LOG_KEEP)}
                            {--dry-run : List files that would be deleted without deleting}';

    protected $description = 'Prune old deploy *.log files (keeps latest.json)';

    public function handle(): int
    {
        $this->foldProjectOption('user');

        $keep = $this->option('keep') === null ? DeployLogArchive::keep() : (int) $this->option('keep');
        if ($keep < 1) {
            $this->error('--keep must be >= 1');

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $user = $this->option('user');

        try {
            if ($user !== null && $user !== '') {
                $deletedByUser = [
                    (string) $user => DeployLogger::pruneUser((string) $user, $keep, $dryRun),
                ];
            } else {
                $deletedByUser = DeployLogger::pruneAll($keep, $dryRun);
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $total = 0;
        foreach ($deletedByUser as $username => $paths) {
            if ($paths === []) {
                continue;
            }
            $this->info(($dryRun ? '[dry-run] ' : '')."{$username}: ".count($paths).' file(s)');
            foreach ($paths as $path) {
                $this->line('  '.$path);
            }
            $total += count($paths);
        }

        if ($total === 0) {
            $this->info('Nothing to prune (keep='.$keep.').');
        } else {
            $this->info(($dryRun ? 'Would delete' : 'Deleted')." {$total} file(s).");
        }

        return 0;
    }
}
