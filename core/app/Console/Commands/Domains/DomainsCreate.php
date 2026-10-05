<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ResolvesProject;
use App\System;
use App\Lib\Domains\NewDomain;
use App\Lib\Helpers\UpstreamSpec;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\Table;

class DomainsCreate extends Command
{
    use ResolvesProject;

    protected $aliases = ['domains:create', 'projects:domains:create'];

    protected $signature = 'domain:create
        {domain? : Domain name to create}
        {--domain= : Domain name (alternative to the positional argument)}
        {--project= : Project username that will own the domain}
        {--username= : Deprecated alias for --project}
        {--type=addon : Domain type: addon or sub}
        {--parent-domain= : Parent domain (required for type=sub)}
        {--alias=* : Optional alias hostname(s)}
        {--proxy-to= : Optional upstream (port or host:port); creates ProxyRules for 80/443}
        {--no-ssl : Disable SSL for this domain}
        {--force : Skip confirmation}';

    protected $description = 'Create an addon or subdomain under an existing project';

    public function handle(): int
    {
        $this->foldProjectOption();

        $input = $this->readInput();
        if ($input === null) {
            return 1;
        }
        [$domainName, $project, $type, $parentDomain, $aliases] = $input;

        $user = User::findByUsername($project);
        if (!$user) {
            $this->error("Project '{$project}' not found.");
            return 1;
        }

        $upstreamHost = null;
        $upstreamPort = null;
        $proxyToRaw = trim((string) ($this->option('proxy-to') ?? ''));
        if ($proxyToRaw !== '') {
            try {
                [$upstreamHost, $upstreamPort] = UpstreamSpec::parse($proxyToRaw, $user->username);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return 1;
            }
        }

        $refusal = $this->typeRefusal($user, $project, $type, $domainName, $parentDomain)
            ?? $this->nameRefusal($domainName, $aliases);
        if ($refusal !== null) {
            $this->error($refusal);
            return 1;
        }

        $noSsl = (bool) $this->option('no-ssl');
        $this->summarize($user, $domainName, $type, $parentDomain, $aliases, $noSsl, $upstreamHost, $upstreamPort);

        if (!$this->option('force') && !$this->confirm('Create this domain?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $details = NewDomain::details($domainName, $noSsl, $aliases);
        if ($type === 'sub') {
            $details['parent_domain'] = $parentDomain;
        }

        /** @var Domain $domain */
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain' => $domainName,
            'type' => $type,
            'details' => $details,
        ]);

        try {
            $domain->projectDomain()->create();
            $user->project()->syncPhpHandlersScripts();
            $user->project()->syncServices();

            if ($upstreamHost !== null && $upstreamPort !== null) {
                $this->proxyTo($user, $domain, $upstreamHost, $upstreamPort);
            }
        } catch (\Throwable $e) {
            try {
                $domain->delete();
            } catch (\Throwable $cleanupError) {
                // best-effort
            }
            $this->error('Domain create failed: ' . $e->getMessage());
            return 1;
        }

        $this->info("Domain '{$domainName}' created.");
        if ($upstreamHost !== null) {
            $this->line("Proxy rules set → {$upstreamHost}:{$upstreamPort} (listen 80/443).");
        }
        $this->line('');

        return 0;
    }

    /**
     * The options, normalised, or null once a refusal has been printed.
     *
     * @return ?array{0: string, 1: string, 2: string, 3: string, 4: list<string>}
     */
    private function readInput(): ?array
    {
        $positional = strtolower(trim((string) ($this->argument('domain') ?? '')));
        $optionDomain = strtolower(trim((string) ($this->option('domain') ?? '')));
        if ($positional !== '' && $optionDomain !== '' && $positional !== $optionDomain) {
            $this->error("Conflicting domain names: argument '{$positional}' vs --domain='{$optionDomain}'.");
            return null;
        }
        $domainName = $positional !== '' ? $positional : $optionDomain;
        $project = trim((string) $this->option('username'));
        $type = strtolower(trim((string) $this->option('type')));
        $parentDomain = strtolower(trim((string) $this->option('parent-domain')));

        if ($domainName === '') {
            $this->error('Domain name is required (positional argument or --domain).');
            return null;
        }
        if ($project === '') {
            $this->error('--project is required.');
            return null;
        }

        if ($type === 'subdomain') {
            $type = 'sub';
        }
        if (!in_array($type, ['addon', 'sub'], true)) {
            $this->error("--type must be 'addon' or 'sub'.");
            return null;
        }

        $aliases = [];
        $rawAliases = $this->option('alias');
        if (is_array($rawAliases)) {
            foreach ($rawAliases as $alias) {
                if (!is_string($alias) || trim($alias) === '') {
                    continue;
                }
                $aliases[] = strtolower(trim($alias));
            }
        }
        [$domainName, $aliases] = NewDomain::withoutWww($domainName, array_values(array_unique($aliases)));

        return [$domainName, $project, $type, $parentDomain, $aliases];
    }

    /** Why the project cannot take a domain of this type here, or null. */
    private function typeRefusal(User $user, string $project, string $type, string $domainName, string $parentDomain): ?string
    {
        $limit = NewDomain::reachedLimit($user, $type);
        if ($limit !== null) {
            return $type === 'addon'
                ? "Addon domains limit of {$limit} reached."
                : "Subdomains limit of {$limit} reached.";
        }
        if ($type !== 'sub') {
            return null;
        }

        if ($parentDomain === '') {
            return '--parent-domain is required when --type=sub.';
        }
        if (!$user->domains()->getQuery()->where('domain', $parentDomain)->exists()) {
            return "Parent domain '{$parentDomain}' not found for project '{$project}'.";
        }
        if (!Str::endsWith($domainName, $parentDomain)) {
            return "Domain '{$domainName}' must end with parent domain '{$parentDomain}'.";
        }

        return null;
    }

    /** @param list<string> $aliases */
    private function nameRefusal(string $domainName, array $aliases): ?string
    {
        $problem = NewDomain::nameProblem($domainName, $aliases);
        if ($problem === null) {
            return null;
        }

        [$kind, $name] = $problem;

        return match ($kind) {
            NewDomain::INVALID_DOMAIN => "Invalid domain name '{$name}'.",
            NewDomain::DOMAIN_EXISTS => "Domain '{$name}' already exists.",
            NewDomain::INVALID_ALIAS => "Invalid alias '{$name}'.",
            NewDomain::ALIAS_EXISTS => "Alias '{$name}' already exists.",
        };
    }

    /** @param list<string> $aliases */
    private function summarize(
        User $user,
        string $domainName,
        string $type,
        string $parentDomain,
        array $aliases,
        bool $noSsl,
        ?string $upstreamHost,
        ?int $upstreamPort,
    ): void {
        $proxyLabel = ($upstreamHost !== null && $upstreamPort !== null)
            ? "{$upstreamHost}:{$upstreamPort}"
            : '-';

        $this->line('');
        $this->info('Create domain');
        $table = new Table($this->output);
        $table->setRows([
            ['Domain', $domainName],
            ['Project', $user->username],
            ['Type', $type],
            ['Parent domain', $type === 'sub' ? $parentDomain : '-'],
            ['Aliases', $aliases === [] ? '-' : implode("\n", $aliases)],
            ['SSL', $noSsl ? 'disabled' : 'enabled'],
            ['Document root', "/{$domainName}/public_html"],
            ['Proxy to', $proxyLabel],
        ]);
        $table->render();
    }

    /** Route the domain's 80 and 443 to the upstream, then rebuild it. */
    private function proxyTo(User $user, Domain $domain, string $upstreamHost, int $upstreamPort): void
    {
        $domainName = $domain->domain;
        foreach ([80, 443] as $listenPort) {
            ProxyRule::updateOrCreate(
                [
                    'owner_scope' => 'user',
                    'username' => $user->username,
                    'transport' => 'http',
                    'listen_port' => $listenPort,
                    'server_name' => $domainName,
                ],
                [
                    'enabled' => true,
                    'listen_ip' => '*',
                    'upstream_host' => $upstreamHost,
                    'upstream_port' => $upstreamPort,
                    'upstream_protocol' => 'http',
                    'is_generated' => false,
                    'metadata' => [
                        'source' => 'domain-create',
                        'description' => "{$listenPort}→{$upstreamPort} for {$domainName}",
                    ],
                ]
            );
        }
        $domain->projectDomain()->rebuild();
        (new System())->webserver()->scheduleWebserverReloadInBackground();
    }
}
