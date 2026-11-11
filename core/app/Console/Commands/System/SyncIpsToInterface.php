<?php

namespace App\Console\Commands\System;

use App\Lib\Ip\HostIpSync;
use Illuminate\Console\Command;

class SyncIpsToInterface extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:sync-ips-to-interface'];

    protected $signature = 'system:ip:sync {--restart-networking} {--skip-errors}';

    protected $description = 'Synchronized IP subnets and addresses from database to network interface.';

    public function handle(HostIpSync $sync): int
    {
        try {
            $lines = $sync->sync((bool) $this->option('restart-networking'));
        } catch (\Exception $e) {
            if ($this->option('skip-errors')) {
                return 0;
            }
            $this->error($e->getMessage());

            return 1;
        }

        foreach ($lines as $line) {
            $line['ok'] ? $this->info($line['message']) : $this->error($line['message']);
        }

        return in_array(false, array_column($lines, 'ok'), true) && ! $this->option('skip-errors') ? 1 : 0;
    }
}
