<?php

namespace App\Console\Commands\Overlay;

use App\Console\Commands\Concerns\ReadsOverlayOptions;
use App\Console\Commands\Concerns\ReadsProjectOption;
use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class ProjectOverlaySetCommand extends Command
{
    use ReadsOverlayOptions, ReadsProjectOption;

    protected $signature = 'project:overlay:set
        {--project= : Project username}
        {--html= : The overlay HTML}
        {--file= : Read the overlay HTML from this file}
        {--position=body_end : body_end (before </body>) or head_end (before </head>)}
        {--hide-global : Keep the global overlay off this project}';

    protected $description = 'Write an HTML overlay (a banner, a script) into the pages of one project';

    public function handle(OverlayService $overlays): int
    {
        try {
            $project = $this->projectOption();
            $overlay = $this->overlayOptions();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $overlays->setProject($project, $overlay, ! $this->option('hide-global'));
        $this->info("Overlay set for project '{$project->username}'.");

        return 0;
    }
}
