<?php

namespace App\Lib\Project;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\TenantEgressGuard;

/**
 * The tenant firewall's file of projects that may call the Engine API: a file so the rule outlives the process that
 * granted it, root-owned so no tenant can write it, replaced atomically as a half-written one would drop a rule in force.
 */
class ApiAccessFirewall
{
    public const FILE = '/opt/panelalpha/shared-hosting/config/tenant-api-access';

    public function __construct(private ?System $system = null) {}

    /**
     * Each touched account's own {@see TenantEgressGuard} is rewritten to follow its allowance.
     *
     * @param  list<string>  $projects  the accounts that may reach the API
     * @param  list<string>  $touched  the accounts whose allowance just changed; one that lost it is not in $projects any more
     */
    public function apply(array $projects, array $touched = []): void
    {
        $system = $this->system ?? new System;
        $system->filesystem()->writeFileReplacingPath(
            self::FILE,
            self::contents($projects),
            'root:root',
            '0644'
        );
        $system->exec(TenantNetwork::firewallArgv(), [], 60);

        foreach (array_unique($touched) as $project) {
            $this->rewriteGuard($system, $project);
        }
    }

    /**
     * The account's own egress guard, rendered from its current allowance. An account without
     * a directory yet is skipped: the create renders the same file from the same allowance.
     */
    private function rewriteGuard(System $system, string $project): void
    {
        $user = User::findByUsername($project);
        if ($user === null) {
            return;
        }

        $runtime = $user->project($system)->runtime();
        if ($runtime instanceof Dind) {
            $runtime->rewriteEgressGuard();
        }
    }

    /** @param list<string> $projects */
    public static function contents(array $projects): string
    {
        $names = [];
        foreach ($projects as $project) {
            if (is_string($project) && $project !== '') {
                $names[] = $project;
            }
        }

        return $names === [] ? '' : implode("\n", $names)."\n";
    }
}
