<?php

namespace App\Console\Commands\System;

use App\Lib\Helpers\Config;
use Illuminate\Console\Command;

class VersionCommand extends Command
{
    protected $signature = 'system:version';

    protected $description = 'Display the system version';

    public function handle(): int
    {
        $ver = Config::getStringValue('system.version');
        if (! $ver) {
            $ver = 'unknown';
        }
        $this->line($ver);

        return 0;
    }
}
