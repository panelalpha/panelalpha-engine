<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\PublicRoutingKinds;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Every project that is allowed public routing or asks for it, with what came of its last deploy.
 */
class PublicRoutingListCommand extends Command
{
    protected $signature = 'system:public-routing:list';

    protected $description = "List the projects allowed public ports or the server's own name, and what their panelalpha.yaml asks for";

    public function handle(PublicRoutingGrants $grants): int
    {
        $rows = [];
        foreach (User::query()->orderBy('username')->get() as $user) {
            $row = self::row($user, $grants->of($user->username));
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            $this->line('No project is allowed public routing or asks for it.');

            return 0;
        }

        $this->table(['Project', 'Allowed', 'Domain', 'Public ports'], $rows);

        return 0;
    }

    /**
     * @param  list<string>  $allowed
     * @return ?list<string>
     */
    private static function row(User $user, array $allowed): ?array
    {
        $recorded = ProjectPublicRouting::recorded($user);
        if ($allowed === [] && ProjectPublicRouting::declared($user)->isEmpty()) {
            return null;
        }
        $domain = $recorded['domain'] ?? null;
        $ports = array_map(
            static fn (array $port): string => "{$port['transport']} {$port['listen']}→{$port['to']} {$port['state']}",
            $recorded['ports'] ?? []
        );

        return [
            $user->username,
            PublicRoutingKinds::label($allowed),
            is_array($domain) ? "{$domain['name']} ({$domain['state']})" : '-',
            $ports === [] ? '-' : implode(', ', $ports),
        ];
    }
}
