<?php

namespace App\Console\Commands\Usage;

use App\Lib\Project\ClassicAppType;
use Illuminate\Console\Command;

/** Every minute: whether each PHP hosting project holds WordPress, for the project list's app.type. */
class SampleClassicAppTypeCommand extends Command
{
    protected $signature = 'projects:app-type:sample';

    protected $description = 'Record whether every PHP hosting project holds WordPress';

    public function handle(ClassicAppType $appType): int
    {
        $changed = $appType->sweep();

        if ($this->getOutput()->isVerbose()) {
            $this->line("{$changed} project(s) changed type.");
        }

        return self::SUCCESS;
    }
}
