<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\System;
use App\Lib\Helpers\UpstreamSpec;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class DomainsSetProxy extends Command
{
    use ResolvesProject;

    protected $aliases = ['domains:set-proxy', 'projects:domains:set-proxy'];

    protected $signature = 'domain:set-proxy
        {--domain= : Domain name or alias}
        {--project= : Project username (optional; used as default host for port-only --proxy-to)}
        {--username= : Deprecated alias for --project}
        {--proxy-to= : Upstream port, or host:port where host is the project\'s own name}
        {--port=* : Listen port(s); defaults to 80 and 443}
        {--force : Skip confirmation}';

    protected $description = 'Set HTTP ProxyRule(s) for a domain (SoT for nginx and any attached tunnels)';

    public function handle(): int
    {
        $this->foldProjectOption();

        $domainName = strtolower(trim((string) $this->option('domain')));
        $proxyTo = trim((string) $this->option('proxy-to'));
        $projectOpt = trim((string) $this->option('username'));

        if ($domainName === '') {
            $this->error('--domain is required.');
            return 1;
        }
        if ($proxyTo === '') {
            $this->error('--proxy-to is required (port or host:port).');
            return 1;
        }

        $domain = Domain::findByNameOrAlias($domainName);
        if (!$domain) {
            $this->error("Domain '{$domainName}' not found.");
            return 1;
        }
        $domain->loadMissing('user');
        $user = $domain->user;
        if (!$user) {
            $this->error('Domain has no project/user.');
            return 1;
        }
        if ($projectOpt !== '' && strtolower($projectOpt) !== strtolower($user->username)) {
            $this->error(
                "Domain '{$domain->domain}' belongs to project '{$user->username}', not '{$projectOpt}'."
            );
            return 1;
        }

        $username = $user->username;
        $fqdn = $domain->domain;

        try {
            [$upstreamHost, $upstreamPort] = UpstreamSpec::parse($proxyTo, $username);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }
        // The rules are the project's own.
        $refusal = ProxyRuleUpstream::refusal('user', $username, $upstreamHost);
        if ($refusal !== null) {
            $this->error($refusal);
            return 1;
        }

        $listenPorts = $this->resolveListenPorts();
        if ($listenPorts === null) {
            return 1;
        }

        $hasTunnels = $domain->hasTunnels();
        $changes = [];
        foreach ($listenPorts as $listenPort) {
            $existing = ProxyRule::query()
                ->where('owner_scope', 'user')
                ->where('username', $username)
                ->where('transport', 'http')
                ->where('listen_port', $listenPort)
                ->where('server_name', $fqdn)
                ->first();

            $current = $existing
                ? "{$existing->upstream_host}:{$existing->upstream_port}"
                    . ($existing->enabled ? '' : ' (disabled)')
                : '-';
            $changes[] = [
                'listen' => $listenPort,
                'current' => $current,
                'new' => "{$upstreamHost}:{$upstreamPort}",
                'action' => $existing ? 'update' : 'create',
            ];
        }

        $this->line('');
        $this->info("Set proxy for {$fqdn} (project: {$username})");
        $table = new Table($this->output);
        $table->setHeaders(['Listen', 'Current', 'New', 'Action']);
        $table->setRows(array_map(static fn (array $c): array => [
            (string) $c['listen'],
            $c['current'],
            $c['new'],
            $c['action'],
        ], $changes));
        $table->render();
        if ($hasTunnels) {
            $this->comment(
                "Domain has tunnel(s): Cloudflare ingress will sync to http://127.0.0.1:{$upstreamPort} inside DinD."
            );
        }

        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->info('Cancelled.');
            return 0;
        }

        foreach ($listenPorts as $listenPort) {
            ProxyRule::updateOrCreate(
                [
                    'owner_scope' => 'user',
                    'username' => $username,
                    'transport' => 'http',
                    'listen_port' => $listenPort,
                    'server_name' => $fqdn,
                ],
                [
                    'enabled' => true,
                    'listen_ip' => '*',
                    'upstream_host' => $upstreamHost,
                    'upstream_port' => $upstreamPort,
                    'upstream_protocol' => 'http',
                    'is_generated' => false,
                    'metadata' => [
                        'source' => 'domain-set-proxy',
                        'description' => "{$listenPort}→{$upstreamPort} for {$fqdn}",
                    ],
                ]
            );
        }

        if ($hasTunnels) {
            try {
                TunnelManager::syncFromProxyRules($user, $domain);
            } catch (CloudflareException $e) {
                $this->error($e->getMessage());
                return 1;
            } catch (\Throwable $e) {
                $this->error('Tunnel ingress sync failed: ' . $e->getMessage());
                return 1;
            }
        }

        $domain->projectDomain()->rebuild();
        (new System())->webserver()->scheduleWebserverReloadInBackground();
        $this->info($hasTunnels
            ? 'Proxy rules updated; nginx rebuilt; tunnel ingress synced.'
            : 'Proxy rules updated; domain vhost rebuilt.');
        $this->line('');

        return 0;
    }

    /**
     * @return list<int>|null
     */
    private function resolveListenPorts(): ?array
    {
        $raw = $this->option('port');
        if (!is_array($raw) || $raw === []) {
            return [80, 443];
        }

        $ports = [];
        foreach ($raw as $value) {
            if (!is_numeric($value) || (int) $value < 1 || (int) $value > 65535) {
                $this->error("Invalid --port '{$value}'.");
                return null;
            }
            $ports[] = (int) $value;
        }

        $ports = array_values(array_unique($ports));
        sort($ports);

        return $ports;
    }
}
