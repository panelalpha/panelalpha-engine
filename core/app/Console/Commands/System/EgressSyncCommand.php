<?php

namespace App\Console\Commands\System;

use App\Lib\Net\Outbound\OutboundStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Brings the outbound limits' events up to date from the root broker, every minute from the scheduler, so a block
 * reaches the project's deploy log and monitoring while it lasts.
 */
class EgressSyncCommand extends Command
{
    protected $signature = 'system:egress:sync';

    protected $description = 'Record the projects and builds the outbound limits stopped';

    public function handle(OutboundStatus $status): int
    {
        try {
            $report = $status->refresh(Carbon::now('UTC'));
        } catch (\Throwable $e) {
            Log::warning('Outbound limits: could not read the root broker\'s report: '.$e->getMessage());
            $this->error('Could not read the outbound limits: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line($report->active ? 'The outbound limits are in place.' : 'The outbound limits are not in place on this server.');

        return self::SUCCESS;
    }
}
