<?php

namespace App\Console\Commands\System;

use App\System;
use App\System\Network;
use Illuminate\Console\Command;

class BuildIpv4NatMap extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:build-ipv4-nat-map'];

    protected $signature = 'system:nat:build
                            {--lookup-url= : URL used to discover the public IP for each local IP (default: https://icanhazip.com)}
                            {--replace-default-ipv4 : Replace default_ipv4 with the discovered public IP when it matches a local IP}';

    protected $description = 'Discover IPv4 NAT 1:1 mappings and store them in the database.';

    public function handle(): int
    {
        $lookupUrl = $this->option('lookup-url') ?: Network::DEFAULT_IPV4_NAT_LOOKUP_URL;
        $replaceDefaultIpv4 = (bool) $this->option('replace-default-ipv4');

        $result = (new System)->network()->rebuildIpv4NatMaps($lookupUrl, $replaceDefaultIpv4);

        if (empty($result['maps'])) {
            $this->warn('No IPv4 NAT mappings were discovered.');
        } else {
            $this->info('Discovered IPv4 NAT mappings:');
            foreach ($result['maps'] as $map) {
                $this->info("  {$map->local_ip} => {$map->public_ip}");
            }
        }

        if ($result['default_ipv4_replaced']) {
            $this->info("Replaced default_ipv4: {$result['default_ipv4_previous']} => {$result['default_ipv4_current']}");
        }

        return 0;
    }
}
