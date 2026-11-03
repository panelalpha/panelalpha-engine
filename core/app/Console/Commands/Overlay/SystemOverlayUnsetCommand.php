<?php

namespace App\Console\Commands\Overlay;

use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class SystemOverlayUnsetCommand extends Command
{
    protected $signature = 'system:overlay:unset';

    protected $description = 'Remove the global overlay';

    public function handle(OverlayService $overlays): int
    {
        $overlays->removeGlobal();
        $this->info('Global overlay removed.');

        return 0;
    }
}
