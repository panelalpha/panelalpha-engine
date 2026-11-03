<?php

namespace App\Console\Commands\Overlay;

use App\Console\Commands\Concerns\PrintsOverlayState;
use App\Console\Commands\Concerns\ReadsProjectOption;
use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class ProjectOverlayShowCommand extends Command
{
    use PrintsOverlayState;
    use ReadsProjectOption;

    protected $signature = 'project:overlay:show
        {--project= : Project username}
        {--json : Print JSON}';

    protected $description = 'Show a project\'s overlay and whether the global one reaches it';

    public function handle(OverlayService $overlays): int
    {
        try {
            $project = $this->projectOption();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return $this->printOverlayState($overlays->projectState($project));
    }
}
