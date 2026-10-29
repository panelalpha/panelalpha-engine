<?php

namespace App\Console\Commands\Task;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Lib\Task\TaskPruner;
use Illuminate\Console\Command;

class PruneTasksCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'task:prune
                            {--project= : Limit prune to a single engine username}
                            {--user= : Deprecated alias for --project}
                            {--keep=50 : Newest terminal deploy tasks to keep per project}
                            {--keep-other=10 : Newest terminal tasks to keep per other job-type bucket}
                            {--max-lines=2000 : After dropping dim lines, keep this many newest lines per task}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old terminal tasks and trim their poll-buffer log lines';

    public function handle(): int
    {
        $this->foldProjectOption('user');

        $keep = (int) $this->option('keep');
        $keepOther = (int) $this->option('keep-other');
        $maxLines = (int) $this->option('max-lines');
        $dryRun = (bool) $this->option('dry-run');
        $user = $this->option('user');
        $username = is_string($user) && $user !== '' ? $user : null;

        try {
            $result = TaskPruner::prune($username, $keep, $keepOther, $maxLines, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info($prefix.'Deleted '.$result['deleted_tasks'].' task(s), trimmed '
            .$result['trimmed_logs'].' log line(s).');

        return 0;
    }
}
