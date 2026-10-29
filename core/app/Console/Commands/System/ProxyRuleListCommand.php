<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\ProxyRule;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ProxyRuleListCommand extends Command
{
    use ResolvesProject;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['proxy-rule:list'];

    protected $signature = 'proxy:rule:list {--scope=all : Filter by owner_scope (all, system, user)} {--project= : Filter by username (for user-owned rules)} {--user= : Deprecated alias for --project}';

    protected $description = 'List proxy rules';

    public function handle(): int
    {
        $this->foldProjectOption('user');

        $scope = $this->option('scope');
        $username = $this->option('user');

        $query = ProxyRule::query();

        if ($scope === 'system') {
            $query->systemOwned();
        } elseif ($scope === 'user' && $username) {
            $query->forUser($username);
        } elseif ($scope === 'user') {
            $query->userOwned();
        } elseif ($username) {
            // --project without --scope=user still filters user-owned rows for that project
            $query->forUser($username);
        }

        /** @var Collection<array-key, ProxyRule> $rules */
        $rules = $query->orderBy('owner_scope')->orderBy('username')->orderBy('transport')->orderBy('listen_port')->get();

        if ($rules->isEmpty()) {
            $this->info('No proxy rules found.');

            return 0;
        }

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                'id' => $rule->id,
                'scope' => $rule->owner_scope,
                'user' => $rule->username ?? '-',
                'transport' => $rule->transport,
                'listen' => ($rule->listen_ip ?? '*').':'.$rule->listen_port,
                'server_name' => $rule->server_name ?? '-',
                'upstream' => $rule->upstream_host.':'.$rule->upstream_port,
                'enabled' => $rule->enabled ? 'Y' : 'N',
                'generated' => $rule->is_generated ? 'Y' : 'N',
            ];
        }

        $this->table(
            ['ID', 'Scope', 'User', 'Transport', 'Listen', 'Server Name', 'Upstream', 'Enabled', 'Generated'],
            $rows
        );

        return 0;
    }
}
