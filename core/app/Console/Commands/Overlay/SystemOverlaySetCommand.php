<?php

namespace App\Console\Commands\Overlay;

use App\Console\Commands\Concerns\ReadsOverlayOptions;
use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class SystemOverlaySetCommand extends Command
{
    use ReadsOverlayOptions;

    protected $signature = 'system:overlay:set
        {--html= : The overlay HTML}
        {--file= : Read the overlay HTML from this file}
        {--position=body_end : body_end (before </body>) or head_end (before </head>)}';

    protected $description = 'Write an HTML overlay (a banner, a script) into the pages of every project';

    public function handle(OverlayService $overlays): int
    {
        try {
            $overlay = $this->overlayOptions();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $overlays->setGlobal($overlay);
        $this->info('Global overlay set.');

        return 0;
    }
}
