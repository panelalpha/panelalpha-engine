<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class DomainsDelete extends Command
{
    use ResolvesProject;

    protected $aliases = ['domains:delete', 'projects:domains:delete'];

    protected $signature = 'domain:delete
        {domain? : Domain name or alias to delete}
        {--domain= : Domain name (alternative to the positional argument)}
        {--project= : Project username that owns the domain}
        {--username= : Deprecated alias for --project}
        {--force : Skip confirmation}';

    protected $description = 'Delete a domain (nginx vhost, proxy rules, and any attached tunnels)';

    public function handle(): int
    {
        $this->foldProjectOption();

        $positional = strtolower(trim((string) ($this->argument('domain') ?? '')));
        $optionDomain = strtolower(trim((string) ($this->option('domain') ?? '')));
        if ($positional !== '' && $optionDomain !== '' && $positional !== $optionDomain) {
            $this->error("Conflicting domain names: argument '{$positional}' vs --domain='{$optionDomain}'.");
            return 1;
        }
        $domainName = $positional !== '' ? $positional : $optionDomain;
        $project = trim((string) $this->option('username'));

        if ($domainName === '') {
            $this->error('Domain name is required (positional argument or --domain).');
            return 1;
        }
        if ($project === '') {
            $this->error('--project is required.');
            return 1;
        }

        $user = User::findByUsername($project);
        if (!$user) {
            $this->error("Project '{$project}' not found.");
            return 1;
        }

        $domain = Domain::findByNameOrAlias($domainName);
        if (!$domain || (int) $domain->user_id !== (int) $user->id) {
            $this->error("Domain '{$domainName}' not found for project '{$project}'.");
            return 1;
        }

        if ($domain->subdomains()->exists()) {
            $this->error("Cannot delete domain '{$domain->domain}' while subdomains still exist.");
            return 1;
        }

        $tunnels = Tunnel::forDomain($domain);
        $tunnelLines = array_map(
            static fn (Tunnel $t): string => "{$t->hostname} ({$t->provider})",
            $tunnels
        );

        $this->line('');
        $this->info('Delete domain');
        $table = new Table($this->output);
        $table->setRows([
            ['Domain', $domain->domain],
            ['Project', $user->username],
            ['Type', $domain->type ?? '-'],
            ['Aliases', $domain->getAliases() === [] ? '-' : implode("\n", $domain->getAliases())],
            ['Tunnels', $tunnelLines === [] ? '-' : implode("\n", $tunnelLines)],
        ]);
        $table->render();
        $this->comment('This removes the domain, its proxy rules, nginx vhost, and any attached tunnels.');

        if (!$this->option('force') && !$this->confirm('Delete this domain?')) {
            $this->info('Cancelled.');
            return 0;
        }

        try {
            $domain->projectDomain()->delete();
            $domain->delete();
            $user->project()->syncPhpHandlersScripts();
            $user->project()->syncServices();
        } catch (\Throwable $e) {
            $this->error('Domain delete failed: ' . $e->getMessage());
            return 1;
        }

        $this->info("Domain '{$domainName}' deleted.");
        $this->line('');

        return 0;
    }
}
