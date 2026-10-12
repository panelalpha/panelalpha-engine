<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class DomainsDeleteTunnel extends Command
{
    use ResolvesProject;

    /**
     * Never alias as domains:delete-* — Artisan would resolve `domains:delete` here
     * when no exact domain:delete existed (and would still collide as an abbreviation).
     */
    protected $aliases = [
        'domains:tunnel:delete',
        'projects:domains:tunnel:delete',
    ];

    protected $signature = 'domain:tunnel:delete
        {hostname? : Public tunnel hostname to remove}
        {--hostname= : Public tunnel hostname (alternative to the positional argument)}
        {--domain= : Local domain the tunnel is attached to (optional filter)}
        {--project= : Project username}
        {--username= : Deprecated alias for --project}
        {--force : Skip confirmation}';

    protected $description = 'Detach and delete a public tunnel hostname from a local domain';

    public function handle(): int
    {
        $this->foldProjectOption();

        $project = trim((string) $this->option('username'));
        $domainOpt = strtolower(trim((string) ($this->option('domain') ?? '')));

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
        if ($hostname === '') {
            $this->error('Tunnel hostname is required (positional argument or --hostname).');
            return 1;
        }

        $user = User::findByUsername($project);
        if (!$user) {
            $this->error("Project '{$project}' not found.");
            return 1;
        }

        $tunnel = Tunnel::findByHostname($hostname);
        if (!$tunnel || (int) $tunnel->user_id !== (int) $user->id) {
            $this->error("Tunnel '{$hostname}' not found for project '{$project}'.");
            return 1;
        }

        $tunnel->loadMissing('domain');
        $domain = $tunnel->domain;
        if ($domainOpt !== '') {
            $filter = Domain::findByNameOrAlias($domainOpt);
            if (!$filter || (int) $filter->id !== (int) $tunnel->domain_id) {
                $this->error("Tunnel '{$hostname}' is not attached to domain '{$domainOpt}'.");
                return 1;
            }
        }

        $this->line('');
        $this->info('Delete tunnel');
        $table = new Table($this->output);
        $table->setRows([
            ['Public hostname', $tunnel->hostname],
            ['Provider', $tunnel->provider],
            ['Local domain', $domain?->domain ?? '-'],
            ['Project', $user->username],
        ]);
        $table->render();
        $this->comment('Only the tunnel hostname is removed. Local domain and proxy rules stay.');
        if ($tunnel->isPanelAlpha()) {
            $this->comment(
                'PanelAlpha Online: deletes the remote WithoutDNS site via the licensing proxy, then the local record.'
            );
        }

        if (!$this->option('force') && !$this->confirm('Delete this tunnel?')) {
            $this->info('Cancelled.');
            return 0;
        }

        try {
            TunnelManager::removeTunnel($user, $tunnel);
        } catch (CloudflareException | PanelAlphaException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $this->error('Tunnel delete failed: ' . $e->getMessage());
            return 1;
        }

        $this->info("Tunnel '{$hostname}' deleted.");
        $this->line('');

        return 0;
    }
}
