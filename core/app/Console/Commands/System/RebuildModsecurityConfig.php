<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

class RebuildModsecurityConfig extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:rebuild-modsecurity-config'];

    protected $signature = 'system:modsec:rebuild';

    protected $description = 'Recreate configuration files for ModSecurity.';

    public function handle(): int
    {
        try {
            $system = new System;
            $system->modsec()->rebuildConfig();
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
