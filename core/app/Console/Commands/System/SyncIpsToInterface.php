<?php

namespace App\Console\Commands\System;

use App\Models\IpSubnet;
use App\System;
use Illuminate\Console\Command;

class SyncIpsToInterface extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:sync-ips-to-interface'];

    protected $signature = 'system:ip:sync {--restart-networking} {--skip-errors}';

    protected $description = 'Synchronized IP subnets and addresses from database to network interface.';

    public function handle(): int
    {
        try {
            $system = new System;

            $route4 = null;
            try {
                $route4 = $system->network()->getDefaultIpv4Route();
            } catch (\Exception $e) {
                $this->warn('Could not get default IPv4 route: '.$e->getMessage());
            }

            $route6 = null;
            try {
                $route6 = $system->network()->getDefaultIpv6Route();
            } catch (\Exception $e) {
                $this->warn('Could not get default IPv6 route: '.$e->getMessage());
            }

            if ($this->option('restart-networking')) {
                $system->runProcessOnHost(['service', 'networking', 'restart']);
            }

            $subnets = IpSubnet::with('ipAssigned')->get();
            foreach ($subnets as $subnet) {
                /** @var IpSubnet $subnet */
                $route = $subnet->family === 4 ? $route4 : $route6;
                if (! $route) {
                    $this->warn("Skipping subnet {$subnet->ip}/{$subnet->mask} due to missing route.");

                    continue;
                }

                try {
                    $cmd = $system->network()->generateIpRouteAddCommand($subnet->ip, $subnet->mask, $subnet->family, $route['gateway'], $route['interface']);
                    $system->runProcessOnHost($cmd);
                    $this->info("Added IP route for {$subnet->ip}/{$subnet->mask}: ".implode(' ', $cmd));
                } catch (\Exception $e) {
                    $this->warn("Could not add IP route {$subnet->ip}/{$subnet->mask}: ".$e->getMessage());
                }

                foreach ($subnet->listAssignedIpAddresses() as $ip) {
                    try {
                        $cmd = $system->network()->generateIpAddressAddCommand($ip, $subnet->mask, $subnet->family, $route['interface']);
                        $system->runProcessOnHost($cmd);
                        $this->info("Added IP address {$ip}: ".implode(' ', $cmd));
                    } catch (\Exception $e) {
                        $this->warn("Could not add IP address {$ip}/{$subnet->mask}: ".$e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            if ($this->option('skip-errors')) {
                return 0;
            }
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
