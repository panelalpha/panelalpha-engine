<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\AppliesProxyRules;
use App\Models\ProxyRule;
use App\Rules\UpstreamHost;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;
use App\System\Services\Webserver\ProxyRuleServerName;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProxyRuleUpdateCommand extends Command
{
    use AppliesProxyRules;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['proxy-rule:update'];

    protected $signature = 'proxy:rule:update {id : Rule ID} {--upstream-host= : New upstream host} {--upstream-port= : New upstream port} {--upstream-protocol= : New upstream protocol} {--enabled= : Enable/disable rule (1/0)} {--force : Skip confirmation}';

    protected $description = 'Update a proxy rule';

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

        $updates = [];

        $hostOption = $this->option('upstream-host');
        if (!empty($hostOption) && is_string($hostOption)) {
            $updates['upstream_host'] = $hostOption;
        }

        $portOption = $this->option('upstream-port');
        if (!empty($portOption) && is_string($portOption)) {
            $port = (int)$portOption;
            if ($port < 1 || $port > 65535) {
                $this->error('Invalid port number.');
                return 1;
            }
            $updates['upstream_port'] = $port;
        }

        $protocolOption = $this->option('upstream-protocol');
        if (!empty($protocolOption) && is_string($protocolOption)) {
            $updates['upstream_protocol'] = $protocolOption;
        }

        $enabledOption = $this->option('enabled');
        if ($enabledOption === '0' || $enabledOption === '1') {
            $updates['enabled'] = (bool)$enabledOption;
        }

        if (empty($updates)) {
            $this->error('No updates provided.');
            return 1;
        }

        // The same rules as PUT /proxy-rules/{id}: these go into the shared proxy config verbatim.
        $validator = Validator::make($updates, [
            'upstream_host' => [new UpstreamHost()],
            'upstream_protocol' => [Rule::in(['http', 'https'])],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }
            return 1;
        }

        // Its port is the rule's own while it is enabled; only switching it on takes a new one.
        if (($updates['enabled'] ?? false) && !$rule->enabled) {
            $refusal = (new ProxyListenPort(app(System::class)))
                ->refusal($rule->transport, $rule->listen_ip ?? '*', $rule->listen_port);
            if ($refusal !== null) {
                $this->error($refusal);
                return 1;
            }
        }

        // As the API: checked whenever the rule stays or goes live.
        if ($updates['enabled'] ?? $rule->enabled) {
            $host = (string) ($updates['upstream_host'] ?? $rule->upstream_host);
            $refusal = ProxyRuleUpstream::refusal($rule->owner_scope, $rule->username, $host)
                ?? ProxyRuleServerName::refusal($rule->owner_scope, $rule->username, $rule->server_name);
            if ($refusal !== null) {
                $this->error($refusal);
                return 1;
            }
        }

        $this->info('Current values:');
        $this->line("  Upstream Host: " . $rule->upstream_host);
        $this->line("  Upstream Port: " . $rule->upstream_port);
        $this->line("  Upstream Protocol: " . ($rule->upstream_protocol ?? '-'));
        $this->line("  Enabled: " . ($rule->enabled ? 'Yes' : 'No'));

        $this->newLine();
        $this->info('New values:');
        foreach ($updates as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }
            $this->line("  " . ucfirst(str_replace('_', ' ', $key)) . ": $value");
        }

        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $rule->update($updates);
        $this->info('Rule updated successfully.');
        $this->applyProxyRules();

        return 0;
    }
}
