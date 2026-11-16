<?php

namespace App\Console\Commands\Overlay;

use App\Console\Commands\Concerns\PrintsOverlayState;
use App\Lib\Overlay\OverlayService;
use Illuminate\Console\Command;

class SystemOverlayShowCommand extends Command
{
    use PrintsOverlayState;

    protected $signature = 'system:overlay:show
        {--json : Print JSON}';

    protected $description = 'Show the global overlay';

    public function handle(OverlayService $overlays): int
    {
        return $this->printOverlayState(['overlay' => $overlays->global()?->toArray()]);
    }
}
