<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\AppliesProxyRules;
use App\Models\ProxyRule;
use Illuminate\Console\Command;

class ProxyRuleDeleteCommand extends Command
{
    use AppliesProxyRules;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['proxy-rule:delete'];

    protected $signature = 'proxy:rule:delete {id : Rule ID} {--force : Skip confirmation}';

    protected $description = 'Delete a proxy rule';

    public function handle(): int
    {
        $id = $this->argument('id');
        if (!is_string($id)) {
            $this->error('Invalid id.');
            return 1;
        }

        /** @var ?ProxyRule */
        $rule = ProxyRule::find((int)$id);

        if (!$rule) {
            $this->error("Rule with ID {$id} not found.");
            return 1;
        }

        $this->info('Rule details:');
        $this->line("  Transport: {$rule->transport}");
        $this->line("  Listen: " . ($rule->listen_ip ?? '*') . ":{$rule->listen_port}");
        if ($rule->server_name) {
            $this->line("  Server Name: {$rule->server_name}");
        }
        $this->line("  Upstream: {$rule->upstream_host}:{$rule->upstream_port}");

        if (!$this->option('force') && !$this->confirm('Delete this rule?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $rule->delete();
        $this->info('Rule deleted successfully.');
        $this->applyProxyRules();

        return 0;
    }
}
