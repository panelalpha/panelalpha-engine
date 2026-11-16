<?php

namespace App\Console\Commands\System;

use App\Lib\Net\Outbound\OutboundStatus;
use App\Rules\EgressOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lifts the outbound block of a project or a build the limits took for a scanner, and gives its addresses a fresh
 * count. A block the limits lift on their own needs nothing.
 */
class EgressUnblockCommand extends Command
{
    protected $signature = 'system:egress:unblock {owner : The project, or the build, the block names (pae system:egress:list)}';

    protected $description = 'Lift the outbound block of a project or a build';

    public function handle(OutboundStatus $status): int
    {
        $owner = (string) $this->argument('owner');
        if (! EgressOwner::accepts($owner)) {
            $this->error("'{$owner}' is not a project or build name.");

            return self::FAILURE;
        }

        try {
            $addresses = $status->unblock($owner, OutboundStatus::LIFTED_BY_CLI, Carbon::now('UTC'));
        } catch (\Throwable $e) {
            $this->error('Could not lift the block: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line($addresses === []
            ? "{$owner} has no outbound block."
            : "Lifted the outbound block of {$owner} (".implode(', ', $addresses).'); its count of new addresses starts again.');

        return self::SUCCESS;
    }
}
