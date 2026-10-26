<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

class RebuildSftpAccounts extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:rebuild-sftp-accounts'];

    protected $signature = 'system:sftp:rebuild';

    protected $description = 'Recreate configuration files for sftp accounts from database.';

    public function handle(): int
    {
        try {
            $system = new System;
            $system->sftp()->rebuildSftpAccounts();
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

        return 0;
    }
}
