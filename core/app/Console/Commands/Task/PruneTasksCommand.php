<?php

namespace App\Console\Commands\Task;

use App\Lib\Task\TaskPruner;
use Illuminate\Console\Command;

class PruneTasksCommand extends Command
{
    protected $signature = 'task:prune
                            {--project= : Limit prune to a single engine username}
                            {--keep=50 : Newest terminal deploy tasks to keep per project; 0 keeps none}
                            {--keep-other=10 : Newest terminal tasks to keep per other job-type bucket; 0 keeps none}
                            {--max-lines=2000 : After dropping dim lines, keep this many newest lines per task}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old terminal tasks and trim their poll-buffer log lines';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $user = $this->option('project');
        $username = is_string($user) && $user !== '' ? $user : null;

        try {
            $result = TaskPruner::prune(
                $username,
                $this->integerOption('keep'),
                $this->integerOption('keep-other'),
                $this->integerOption('max-lines'),
                $dryRun,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info($prefix.'Deleted '.$result['deleted_tasks'].' task(s), trimmed '
            .$result['trimmed_logs'].' log line(s).');

        return 0;
    }

    /**
     * An option left empty or not a number is refused rather than read as 0, which keeps no task.
     *
     * @throws \InvalidArgumentException
     */
    private function integerOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException($name.' must be an integer');
        }

        return $value;
    }
}
