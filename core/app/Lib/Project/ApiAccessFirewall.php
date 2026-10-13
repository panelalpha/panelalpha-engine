<?php

namespace App\Lib\Project;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System;
use App\System\Project\Dind;

/**
 * The tenant firewall's list of projects allowed to call the Engine API.
 *
 * The script reads the file instead of being handed the names, so the rule
 * outlives the process that granted it: every apply of the firewall on this
 * host, at boot included, agrees on who may reach :2011. A tenant must not be
 * able to write it, and a half-written one would drop a rule that is in force,
 * so it is root-owned and replaced atomically.
 *
 * An account that has been allowed also carries an egress guard of its own
 * ({@see \App\System\Project\Dind\TenantEgressGuard}), which knows nothing of that
 * host port until it is rewritten here: the account's service re-applies it
 * within seconds, and nobody has to enter the account.
 */
class ApiAccessFirewall
{
    public const FILE = '/opt/panelalpha/shared-hosting/config/tenant-api-access';

    public function __construct(private ?System $system = null)
    {
    }

    /**
     * @param list<string> $projects the accounts that may reach the API
     * @param list<string> $touched the accounts whose allowance just changed, whose
     *        own egress guard has to follow it -- an account that lost its
     *        allowance is not in $projects any more
     */
    public function apply(array $projects, array $touched = []): void
    {
        $system = $this->system ?? new System();
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
     * The account's own egress guard, rendered from its allowance as it now
     * stands. An account without a directory yet is skipped: the create renders the
     * same file from the same allowance.
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

        return $names === [] ? '' : implode("\n", $names) . "\n";
    }
}
