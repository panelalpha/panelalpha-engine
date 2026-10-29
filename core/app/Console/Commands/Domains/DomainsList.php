<?php

namespace App\Console\Commands\Domains;

use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Console\Command;

class DomainsList extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['domains:list', 'projects:domains:list'];

    protected $signature = 'domain:list {--project= : Only this project\'s domains}';

    protected $description = 'List domains in the engine database, for one project or all of them';

    public function handle(): int
    {
        $project = $this->option('project');

        $data = [];
        $totalDomains = 0;
        $totalAliases = 0;
        $totalTunnels = 0;
        $usernames = [];
        $query = Domain::with(['user', 'tunnels'])->orderBy('created_at');
        if (is_string($project) && $project !== '') {
            $user = User::findByUsername($project);
            if (! $user) {
                $this->error("No project named '{$project}'");

                return 1;
            }
            $query->where('user_id', $user->id);
        }
        $domains = $query->get();
        foreach ($domains as $domain) {
            /** @var Domain $domain */
            $tunnelLabels = $domain->tunnels
                ->map(static fn (Tunnel $t): string => "{$t->hostname} ({$t->provider})")
                ->all();
            $data[] = [
                'username' => $domain->user?->username,
                'domain' => $domain->domain,
                'aliases' => implode("\n", $domain->getAliases()),
                'tunnels' => $tunnelLabels === [] ? '' : implode("\n", $tunnelLabels),
                'created_at' => $domain->created_at,
            ];
            $totalDomains++;
            $totalAliases += count($domain->getAliases());
            $totalTunnels += count($tunnelLabels);
            $domain->user && ($usernames[] = $domain->user->username);
        }
        $totalUsers = count(array_unique($usernames));

        $this->table(
            ['Project', 'Domain', 'Aliases', 'Tunnels', 'Created'],
            $data,
        );
        $this->info(
            'Total domains: '.$totalDomains
            .' (+'.$totalAliases.' aliases, +'.$totalTunnels.' tunnels)'
        );
        $this->info('Total projects: '.$totalUsers);

        return 0;
    }
}
