<?php

namespace App\Console\Commands\Overlay;

use App\Console\Commands\Concerns\ReadsProjectOption;
use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class ProjectOverlayUnsetCommand extends Command
{
    use ReadsProjectOption;

    protected $signature = 'project:overlay:unset
        {--project= : Project username}';

    protected $description = 'Remove a project\'s own overlay and show the global one on it again';

    public function handle(OverlayService $overlays): int
    {
        try {
            $project = $this->projectOption();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $overlays->removeProject($project);
        $this->info("Overlay removed from project '{$project->username}'.");

        return 0;
    }
}
