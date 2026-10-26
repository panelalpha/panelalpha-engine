<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class DomainsCreateTunnel extends Command
{
    use ResolvesProject;

    /**
     * Do not alias as domains:create-* — exact domains:create already exists, but
     * prefer domain:tunnel:* so tunnel ops are clearly namespaced.
     */
    protected $aliases = [
        'domains:tunnel:create',
        'projects:domains:tunnel:create',
        // Old spellings from the first simplify pass (safe: domains:create wins exact match).
        'domain:create-tunnel',
        'domains:create-tunnel',
        'projects:domains:create-tunnel',
    ];

    protected $signature = 'domain:tunnel:create
        {hostname? : Public tunnel hostname (e.g. admin.example.com)}
        {--hostname= : Public tunnel hostname (alternative to the positional argument)}
        {--domain= : Existing local domain to attach the tunnel to}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--provider=cloudflare : Tunnel provider (cloudflare|panelalpha)}
        {--force : Skip confirmation}';

    protected $description = 'Attach a public tunnel hostname (Cloudflare or PanelAlpha Online) to an existing local domain';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $domainOpt = strtolower(trim((string) $this->option('domain')));
        $provider = strtolower(trim((string) $this->option('provider')));

        $positional = strtolower(trim((string) ($this->argument('hostname') ?? '')));
        $optionHostname = strtolower(trim((string) ($this->option('hostname') ?? '')));
        if ($positional !== '' && $optionHostname !== '' && $positional !== $optionHostname) {
            $this->error("Conflicting hostnames: argument '{$positional}' vs --hostname='{$optionHostname}'.");

            return 1;
        }
        $hostname = $positional !== '' ? $positional : $optionHostname;

        if ($project === '') {
            $this->error('--project is required.');

            return 1;
        }
        if ($domainOpt === '') {
            $this->error('--domain is required.');

            return 1;
        }
        if ($hostname === '') {
            $this->error('Tunnel hostname is required (positional argument or --hostname).');

            return 1;
        }
        if (! in_array($provider, Tunnel::PROVIDERS, true)) {
            $this->error('--provider must be one of: '.implode(', ', Tunnel::PROVIDERS));

            return 1;
        }
        if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->error("Invalid tunnel hostname '{$hostname}'.");

            return 1;
        }

        $user = User::findByUsername($project);
        if (! $user) {
            $this->error("Project '{$project}' not found.");

            return 1;
        }

        $domain = Domain::findByNameOrAlias($domainOpt);
        if (! $domain || (int) $domain->user_id !== (int) $user->id) {
            $this->error("Domain '{$domainOpt}' not found for project '{$project}'.");

            return 1;
        }

        // Ask now what creating would ask anyway, so a refusal arrives before
        // the confirmation prompt rather than after it. Same guard the API
        // calls, so the two cannot drift apart.
        try {
            [$hostname, $provider] = TunnelManager::assertCreatable($user, $domain, $hostname, $provider);
        } catch (CloudflareException|PanelAlphaException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->line('');
        $this->info('Create tunnel');
        $table = new Table($this->output);
        $table->setRows([
            ['Local domain', $domain->domain],
            ['Project', $user->username],
            ['Provider', $provider],
            ['Public hostname', $hostname],
        ]);
        $table->render();
        if ($provider === Tunnel::PROVIDER_PANELALPHA) {
            $this->comment(
                'WDNS will send traffic to the local domain at this engine\'s public IP (default_ipv4).'
            );
        } else {
            $this->comment('Local domain keeps nginx routing; the public hostname is exposed via the tunnel.');
        }

        if (! $this->option('force') && ! $this->confirm('Create this tunnel?')) {
            $this->info('Cancelled.');

            return 0;
        }

        try {
            $tunnel = TunnelManager::createTunnel($user, $domain, $hostname, $provider);
        } catch (CloudflareException|PanelAlphaException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error('Tunnel create failed: '.$e->getMessage());

            return 1;
        }

        $this->info("Tunnel '{$tunnel->hostname}' ({$tunnel->provider}) attached to '{$domain->domain}'.");
        $rulesExist = ProxyRule::query()
            ->where('transport', 'http')
            ->where('enabled', true)
            ->where('server_name', $domain->domain)
            ->exists();
        if (! $rulesExist) {
            $this->line('No proxy rules yet — run domains:set-proxy so the tunnel has an upstream.');
        }
        $this->line('');

        return 0;
    }
}
