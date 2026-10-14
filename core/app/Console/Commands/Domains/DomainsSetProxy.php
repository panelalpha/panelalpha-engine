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

        $domain = $this->ownedDomain($domainName, $projectOpt);
        if ($domain === null) {
            return 1;
        }
        $user = $domain->user;

        $upstream = $this->upstream($proxyTo, $user->username);
        if ($upstream === null) {
            return 1;
        }
        [$upstreamHost, $upstreamPort] = $upstream;

        $listenPorts = $this->resolveListenPorts();
        if ($listenPorts === null) {
            return 1;
        }

        $hasTunnels = $domain->hasTunnels();
        $this->showPlan($domain, $listenPorts, $upstreamHost, $upstreamPort, $hasTunnels);

        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $this->writeRules($domain, $listenPorts, $upstreamHost, $upstreamPort);
        $tunnelError = $hasTunnels ? $this->syncTunnels($domain) : null;

        $domain->projectDomain()->rebuild();
        (new System())->webserver()->scheduleWebserverReloadInBackground();

        return $this->reportApplied($tunnelError, $hasTunnels);
    }

    /** The domain with its owner loaded, or null once a refusal has been printed. */
    private function ownedDomain(string $domainName, string $projectOpt): ?Domain
    {
        $domain = Domain::findByNameOrAlias($domainName);
        if (!$domain) {
            $this->error("Domain '{$domainName}' not found.");
            return null;
        }
        $domain->loadMissing('user');
        $user = $domain->user;
        if (!$user) {
            $this->error('Domain has no project/user.');
            return null;
        }
        if ($projectOpt !== '' && strtolower($projectOpt) !== strtolower($user->username)) {
            $this->error(
                "Domain '{$domain->domain}' belongs to project '{$user->username}', not '{$projectOpt}'."
            );
            return null;
        }

        return $domain;
    }

    /**
     * The upstream as [host, port], or null once a refusal has been printed.
     * The rules are the project's own.
     *
     * @return ?array{0: string, 1: int}
     */
    private function upstream(string $proxyTo, string $username): ?array
    {
        try {
            [$upstreamHost, $upstreamPort] = UpstreamSpec::parse($proxyTo, $username);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return null;
        }

        $refusal = ProxyRuleUpstream::refusal('user', $username, $upstreamHost);
        if ($refusal !== null) {
            $this->error($refusal);
            return null;
        }

        return [$upstreamHost, $upstreamPort];
    }

    /** @param list<int> $listenPorts */
    private function showPlan(Domain $domain, array $listenPorts, string $upstreamHost, int $upstreamPort, bool $hasTunnels): void
    {
        $this->line('');
        $this->info("Set proxy for {$domain->domain} (project: {$domain->user->username})");
        $table = new Table($this->output);
        $table->setHeaders(['Listen', 'Current', 'New', 'Action']);
        $table->setRows(array_map(
            fn (int $listenPort): array => $this->plannedChange($domain, $listenPort, "{$upstreamHost}:{$upstreamPort}"),
            $listenPorts
        ));
        $table->render();
        if ($hasTunnels) {
            $this->comment(
                "Domain has tunnel(s): Cloudflare ingress will sync to http://127.0.0.1:{$upstreamPort} inside DinD."
            );
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} listen, current, new, action */
    private function plannedChange(Domain $domain, int $listenPort, string $new): array
    {
        $existing = ProxyRule::query()->where($this->ruleKey($domain, $listenPort))->first();

        $current = $existing
            ? "{$existing->upstream_host}:{$existing->upstream_port}"
                . ($existing->enabled ? '' : ' (disabled)')
            : '-';

        return [(string) $listenPort, $current, $new, $existing ? 'update' : 'create'];
    }

    /** @param list<int> $listenPorts */
    private function writeRules(Domain $domain, array $listenPorts, string $upstreamHost, int $upstreamPort): void
    {
        foreach ($listenPorts as $listenPort) {
            ProxyRule::updateOrCreate(
                $this->ruleKey($domain, $listenPort),
                [
                    'enabled' => true,
                    'listen_ip' => '*',
                    'upstream_host' => $upstreamHost,
                    'upstream_port' => $upstreamPort,
                    'upstream_protocol' => 'http',
                    'is_generated' => false,
                    'metadata' => [
                        'source' => 'domain-set-proxy',
                        'description' => "{$listenPort}→{$upstreamPort} for {$domain->domain}",
                    ],
                ]
            );
        }
    }

    /** @return array<string, string|int> the columns that identify one of the domain's HTTP proxy rules */
    private function ruleKey(Domain $domain, int $listenPort): array
    {
        return [
            'owner_scope' => 'user',
            'username' => $domain->user->username,
            'transport' => 'http',
            'listen_port' => $listenPort,
            'server_name' => $domain->domain,
        ];
    }

    /**
     * Push the rules to the domain's tunnels and return the failure, if any.
     * The rules are already the source of truth, so a failed sync must not stop
     * the vhost rebuild; it is reported on its own afterwards.
     */
    private function syncTunnels(Domain $domain): ?string
    {
        try {
            TunnelManager::syncFromProxyRules($domain->user, $domain);
        } catch (CloudflareException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return 'Tunnel ingress sync failed: ' . $e->getMessage();
        }

        return null;
    }

    private function reportApplied(?string $tunnelError, bool $hasTunnels): int
    {
        if ($tunnelError !== null) {
            $this->error($tunnelError);
            $this->warn('The proxy rules were written and the domain vhost rebuilt; only the tunnel ingress sync failed.');

            return 1;
        }

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
