<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class PruneIpv4NatMaps extends Command
{
    protected $signature = 'system:nat:prune';

    protected $description = 'Remove the stored IPv4 NAT maps from addresses this host holds on Docker\'s own interfaces (docker0, its br-* bridges, veths), which no NAT translates.';

    /**
     * The sites rebind as soon as a map goes. A host whose interfaces cannot be read keeps every map and exits
     * non-zero, saying why on stderr for the caller (installer.sh) to see.
     */
    public function handle(): int
    {
        try {
            $system = app(System::class);
            $maps = $system->network()->forgetDockerIpv4NatMaps();
            if ($maps === []) {
                $this->info('No stored IPv4 NAT map is from an address on one of Docker\'s interfaces.');

                return self::SUCCESS;
            }
            foreach ($maps as $map) {
                $this->info("Removed the IPv4 NAT map {$map->local_ip} => {$map->public_ip}: this host holds {$map->local_ip} on one of Docker's interfaces, which no NAT translates.");
            }
            $system->rebuildDomains();
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
