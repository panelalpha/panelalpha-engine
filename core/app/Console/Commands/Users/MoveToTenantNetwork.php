<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\TenantNetworkMove;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\TenantNetworkMove as PhpHostingTenantNetworkMove;

/**
 * Accounts (DinD or PHP hosting) created before pash-tenants existed sit
 * on pash-default-network with core; new ones start on pash-tenants. This moves the old ones, live. Safe to run
 * again: an account already moved is left as it is.
 */
class MoveToTenantNetwork extends ProjectFleetCommand
{
    protected $signature = 'project:network:move' . ProjectOptions::SIGNATURE;

    protected $description = 'Move DinD and PHP hosting projects onto the isolated tenant network (' . TenantNetwork::NAME . ')';

    private string $outcome = '';

    protected function progress(User $user): string
    {
        return "Moving '{$user->username}' to " . TenantNetwork::NAME . '...';
    }

    protected function applyTo(User $user): void
    {
        $runtime = $user->project()->runtime();
        if ($runtime instanceof DindProject) {
            $move = new TenantNetworkMove($runtime);
        } elseif ($runtime instanceof PhpHosting) {
            $move = new PhpHostingTenantNetworkMove($runtime);
        } else {
            $this->outcome = '  Not a DinD or PHP hosting project; nothing to move.';

            return;
        }

        $this->outcome = match ($move->run()) {
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
