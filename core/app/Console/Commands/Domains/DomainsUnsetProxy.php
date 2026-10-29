<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Console\Commands\Concerns\ResolvesProject;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\System;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class DomainsUnsetProxy extends Command
{
    use ConfirmsChanges;
    use ResolvesProject;

    protected $aliases = ['domains:unset-proxy', 'projects:domains:unset-proxy'];

    protected $signature = 'domain:unset-proxy
        {--domain= : Domain name or alias}
        {--project= : Project username (optional check)}
        {--username= : Deprecated alias for --project}
        {--port=* : Listen port(s) to unset; defaults to 80 and 443}
        {--force : Skip confirmation}';

    protected $description = 'Remove HTTP ProxyRule(s) for a domain (SoT). Syncs attached tunnel ingress when present';

    public function handle(): int
    {
        $this->foldProjectOption();

        $domainName = strtolower(trim((string) $this->option('domain')));
        $projectOpt = trim((string) $this->option('username'));
        if ($domainName === '') {
            $this->error('--domain is required.');

            return 1;
        }

        $domain = Domain::findByNameOrAlias($domainName);
        if (! $domain) {
            $this->error("Domain '{$domainName}' not found.");

            return 1;
        }
        $domain->loadMissing('user');
        $user = $domain->user;
        if (! $user) {
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
        $hasTunnels = $domain->hasTunnels();

        $listenPorts = $this->resolveListenPorts();
        if ($listenPorts === null) {
            return 1;
        }

        $changes = [];
        foreach ($listenPorts as $listenPort) {
            $existing = ProxyRule::query()
                ->where('owner_scope', 'user')
                ->where('username', $username)
                ->where('transport', 'http')
                ->where('listen_port', $listenPort)
                ->where('server_name', $fqdn)
                ->first();

            $changes[] = [
                'listen' => $listenPort,
                'current' => $existing
                    ? "{$existing->upstream_host}:{$existing->upstream_port}"
                        .($existing->enabled ? '' : ' (disabled)')
                    : '-',
                'new' => '-',
                'action' => $existing ? 'delete' : 'skip',
                'existing' => $existing,
            ];
        }

        $toDelete = array_filter($changes, static fn (array $c): bool => $c['existing'] !== null);
        if ($toDelete === []) {
            $this->warn("No user HTTP proxy rules for {$fqdn} on ports ".implode(', ', $listenPorts).'.');

            return 0;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $this->line('');
        $this->info("Unset proxy for {$fqdn} (project: {$username})");
        $table = new Table($this->output);
        $table->setHeaders(['Listen', 'Current', 'New', 'Action']);
        $table->setRows(array_map(static fn (array $c): array => [
            (string) $c['listen'],
            $c['current'],
            $c['new'],
            $c['action'],
        ], $changes));
        $table->render();
        $this->comment('Other listen ports (if any) are left unchanged.');
        if ($hasTunnels) {
            $this->comment('Attached tunnel hostname(s) will be removed from ingress until proxy is set again.');
        }

        if (! $this->option('force') && ! $this->confirm('Apply these changes?')) {
            $this->info('Cancelled.');

            return 0;
        }

        foreach ($toDelete as $change) {
            /** @var ProxyRule $rule */
            $rule = $change['existing'];
            $rule->delete();
        }

        if ($hasTunnels) {
            try {
                TunnelManager::syncFromProxyRules($user, $domain);
            } catch (CloudflareException $e) {
                $this->error($e->getMessage());

                return 1;
            } catch (\Throwable $e) {
                $this->error('Tunnel ingress sync failed: '.$e->getMessage());

                return 1;
            }
        }

        $domain->projectDomain()->rebuild();
        (new System)->webserver()->scheduleWebserverReloadInBackground();

        $this->info($hasTunnels
            ? 'Proxy rules removed; tunnel ingress synced; domain vhost rebuilt.'
            : 'Proxy rules removed; domain vhost rebuilt.');
        $this->line('');

        return 0;
    }

    /**
     * @return list<int>|null
     */
    private function resolveListenPorts(): ?array
    {
        $raw = $this->option('port');
        if (! is_array($raw) || $raw === []) {
            return [80, 443];
        }

        $ports = [];
        foreach ($raw as $value) {
            if (! is_numeric($value) || (int) $value < 1 || (int) $value > 65535) {
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
