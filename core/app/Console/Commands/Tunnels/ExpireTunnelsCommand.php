<?php

namespace App\Console\Commands\Tunnels;

use App\Integrations\Tunnels\TunnelManager;
use App\Models\Tunnel;
use Illuminate\Console\Command;

/**
 * Removes the tunnels the engine gave a lifetime that has passed: the
 * registration at the provider, the local row, and the domain's vhost aliases.
 * A tunnel without an engine lifetime never expires and is left alone, even if
 * the proxy put its own `valid_until` on it.
 */
class ExpireTunnelsCommand extends Command
{
    protected $signature = 'tunnels:expire';

    protected $description = 'Remove tunnels whose lifetime has passed';

    public function handle(): int
    {
        $expired = Tunnel::expired();

        if ($expired->isEmpty()) {
            $this->info('No expired tunnels.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($expired as $tunnel) {
            try {
                TunnelManager::removeTunnel($tunnel->user, $tunnel);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Tunnel '{$tunnel->hostname}' could not be expired: " . $e->getMessage());
            }
        }

        $this->info('Expired ' . ($expired->count() - $failed) . ' of ' . $expired->count() . ' tunnel(s).');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
