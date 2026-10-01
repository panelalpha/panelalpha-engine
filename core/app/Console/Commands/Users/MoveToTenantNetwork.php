<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\TenantNetworkMove;

/**
 * Accounts created before engine#519 sit on pash-default-network with core;
 * new ones start on pash-tenants. This moves the old ones, live. Safe to run
 * again: an account already moved is left as it is.
 */
class MoveToTenantNetwork extends ProjectFleetCommand
{
    protected $signature = 'project:network:move' . ProjectOptions::SIGNATURE;

    protected $description = 'Move DinD projects onto the isolated tenant network (' . TenantNetwork::NAME . ')';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Moving '{$user->username}' to " . TenantNetwork::NAME . '...';
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->getTemplate() === 'dind' ? $user->project()->runtime() : null;
        if (!$runtime instanceof DindProject) {
            $this->outcome = '  Not a DinD project; nothing to move.';

            return;
        }

        $this->outcome = match ((new TenantNetworkMove($runtime))->run()) {
            TenantNetworkMove::MOVED => '  Moved.',
            TenantNetworkMove::ALREADY => '  Already on ' . TenantNetwork::NAME . '.',
            TenantNetworkMove::NOT_RUNNING => '  Not running, left where it is: start it and run this again.',
        };
    }

    protected function finished(User $user): string
    {
        return $this->outcome;
    }
}
