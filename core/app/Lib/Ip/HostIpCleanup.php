<?php

namespace App\Lib\Ip;

use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Log;

/**
 * Takes a released address or a deleted subnet's route off the host best-effort:
 * a failure is logged, the record change stands, and false tells the caller the host kept it.
 */
class HostIpCleanup
{
    public function __construct(private readonly System $system) {}

    public function removeRoute(IpSubnet $subnet): bool
    {
        return $this->runOrWarn(
            fn (Network $network) => $network->generateIpRouteDelCommand($subnet->ip, $subnet->mask, $subnet->family),
            "Could not delete IP route for subnet {$subnet->ip}/{$subnet->mask}",
        );
    }

    /** A shared address stays on the interface while another project still holds it. */
    public function removeUnusedAddress(IpAssigned $ip, IpSubnet $subnet): bool
    {
        if ($this->addressStillUsed($ip, $subnet)) {
            return true;
        }

        return $this->runOrWarn(
            fn (Network $network) => $network->generateIpAddressDelCommand($ip->ip_address, $subnet->mask, $subnet->family),
            "Could not delete IP address {$ip->ip_address} from network interface",
        );
    }

    private function addressStillUsed(IpAssigned $ip, IpSubnet $subnet): bool
    {
        return $subnet->is_shared
            && IpAssigned::query()
                ->where('ip_subnet_id', $subnet->id)
                ->where('ip_address', $ip->ip_address)
                ->exists();
    }

    /** @param  callable(Network): list<string>  $command */
    private function runOrWarn(callable $command, string $warning): bool
    {
        try {
            $process = $this->system->runProcessOnHost($command($this->system->network()));
        } catch (\Exception $e) {
            Log::warning($warning, [
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        }
        if (! $process->isSuccessful()) {
            Log::warning($warning, [
                'exit_code' => $process->getExitCode(),
                'error_output' => trim($process->getErrorOutput()),
            ]);

            return false;
        }

        return true;
    }
}
