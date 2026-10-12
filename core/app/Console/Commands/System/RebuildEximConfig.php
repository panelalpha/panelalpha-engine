<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;
use Throwable;

class RebuildEximConfig extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:rebuild-exim-config'];

    protected $signature = 'system:exim:rebuild
        {--networks : Only re-read the addresses Exim listens on and relays for from Docker}';

    protected $description = 'Recreate configuration files for exim4 from database.';

    /**
     * Any failure leaves the listen/relay addresses stale, so it exits non-zero
     * for the caller (bootstrap-from-source.sh) to see.
     */
    public function handle(): int
    {
        try {
            $system = app(System::class);
            if ($this->option('networks')) {
                $system->exim()->rebuildNetworks();
            } else {
                $system->exim()->rebuildEximConfig();
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
