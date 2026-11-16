<?php

namespace App\Jobs;

use App\Integrations\Tunnels\TunnelManager;
use App\Models\Tunnel;
use App\Models\User;
use App\Support\JobBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes one tunnel at the lifetime it was given, rather than at the next
 * `tunnels:expire` sweep, which stays as the fallback for a job that was lost.
 */
class ExpireTunnel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = JobBudget::SECONDS;

    public function __construct(public int $tunnelId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $tunnel = Tunnel::query()->find($this->tunnelId);
        $user = $tunnel?->user;

        if (! $tunnel instanceof Tunnel || ! $user instanceof User || ! $tunnel->isExpired()) {
            return;
        }

        try {
            TunnelManager::removeTunnel($user, $tunnel);
        } catch (Throwable $e) {
            Log::warning("Tunnel {$tunnel->hostname} is past its lifetime but was not removed: ".$e->getMessage());
        }
    }
}
