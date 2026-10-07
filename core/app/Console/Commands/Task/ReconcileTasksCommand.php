<?php

namespace App\Console\Commands\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Task\TaskReconciler;
use Illuminate\Console\Command;

class ReconcileTasksCommand extends Command
{
    protected $signature = 'task:reconcile
                            {--dry-run : Report what would be retired without changing it}
                            {--older-than=120 : Ignore tasks started this many seconds ago}';

    protected $description = 'Retire running tasks and deploys whose process is gone without finishing (host reboot, OOM kill)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $olderThan = max(0, (int) $this->option('older-than'));

        // First: a deploy task whose worker died is still reserved in the
        // queue, so it is retired by adopting the verdict its closed log has.
        $settled = DeployLogger::settleOrphanedDeploys($dryRun);
        $retired = TaskReconciler::reconcile($dryRun, $olderThan);

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info($prefix . 'Closed ' . count($settled) . ' deploy log(s) left running by a process that is gone.'
            . ($settled === [] ? '' : ' accounts: ' . implode(', ', $settled)));
        $this->info($prefix . 'Retired ' . count($retired) . ' orphaned task(s).'
            . ($retired === [] ? '' : ' ids: ' . implode(', ', $retired)));

        return 0;
    }
}
