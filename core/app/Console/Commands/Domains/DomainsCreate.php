<?php

namespace App\Console\Commands\Domains;

use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Console\Commands\Concerns\ResolvesProject;
use App\Lib\Domains\NewDomain;
use App\Lib\Helpers\UpstreamSpec;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\Table;

/**
 * @psalm-type CreateRequest = array{user: User, domain: string, type: string, parent: string,
 *   aliases: list<string>, noSsl: bool, upstreamHost: ?string, upstreamPort: ?int}
 */
class DomainsCreate extends Command
{
    use ConfirmsChanges;
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
        {--proxy-to= : Optional upstream port, or host:port where host is the project\'s own name; creates ProxyRules for 80/443}
        {--no-ssl : Disable SSL for this domain}
        {--force : Skip confirmation}';

    protected $description = 'Create an addon or subdomain under an existing project';

    public function handle(): int
    {
        $this->foldProjectOption();

        $request = $this->validatedRequest();
        if ($request === null) {
            return 1;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $this->summarize($request);

        if (! $this->option('force') && ! $this->confirm('Create this domain?')) {
            $this->info('Cancelled.');

            return 0;
        }

        return $this->create($request);
    }

    /**
     * The domain to create and its project, or null once a refusal has been printed.
     *
     * @return ?CreateRequest
     */
    private function validatedRequest(): ?array
    {
        $input = $this->readInput();
        if ($input === null) {
            return null;
        }
        [$domainName, $project, $type, $parentDomain, $aliases] = $input;

        $user = User::findByUsername($project);
        if (! $user) {
            $this->error("Project '{$project}' not found.");

            return null;
        }

        $upstream = $this->readUpstream($user);
        if ($upstream === null) {
            return null;
        }

        $refusal = $this->typeRefusal($user, $project, $type, $domainName, $parentDomain)
            ?? $this->nameRefusal($domainName, $aliases);
        if ($refusal !== null) {
            $this->error($refusal);

            return null;
        }

        return [
            'user' => $user,
            'domain' => $domainName,
            'type' => $type,
            'parent' => $parentDomain,
            'aliases' => $aliases,
            'noSsl' => (bool) $this->option('no-ssl'),
            'upstreamHost' => $upstream[0],
            'upstreamPort' => $upstream[1],
        ];
    }

    /**
     * The options, normalised, or null once a refusal has been printed.
     *
     * @return ?array{0: string, 1: string, 2: string, 3: string, 4: list<string>}
     */
    private function readInput(): ?array
    {
        $domainName = $this->requestedDomain();
        if ($domainName === null) {
            return null;
        }
        $project = trim((string) $this->option('username'));
        $type = $this->requestedType();
        $parentDomain = self::normalised($this->option('parent-domain'));

        if ($domainName === '') {
            $this->error('Domain name is required (positional argument or --domain).');

            return null;
        }
        if ($project === '') {
            $this->error('--project is required.');

            return null;
        }
        if (! in_array($type, ['addon', 'sub'], true)) {
            $this->error("--type must be 'addon' or 'sub'.");

            return null;
        }

        [$domainName, $aliases] = NewDomain::withoutWww($domainName, $this->requestedAliases());

        return [$domainName, $project, $type, $parentDomain, $aliases];
    }

    /** The domain from the argument or --domain, or null once a conflict between the two has been printed. */
    private function requestedDomain(): ?string
    {
        $positional = self::normalised($this->argument('domain'));
        $optionDomain = self::normalised($this->option('domain'));
        if ($positional !== '' && $optionDomain !== '' && $positional !== $optionDomain) {
            $this->error("Conflicting domain names: argument '{$positional}' vs --domain='{$optionDomain}'.");

            return null;
        }

        return $positional !== '' ? $positional : $optionDomain;
    }

    /** The --type option, with "subdomain" accepted as "sub". */
    private function requestedType(): string
    {
        $type = self::normalised($this->option('type'));

        return $type === 'subdomain' ? 'sub' : $type;
    }

    /** @return list<string> the non-empty --alias values, normalised and unique */
    private function requestedAliases(): array
    {
        $rawAliases = $this->option('alias');
        if (! is_array($rawAliases)) {
            return [];
        }

        $aliases = [];
        foreach ($rawAliases as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $aliases[] = self::normalised($alias);
            }
        }

        return array_values(array_unique($aliases));
    }

    private static function normalised(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }

    /**
     * The --proxy-to upstream as [host, port], [null, null] when none was
     * given, or null once a refusal has been printed. The rules are the project's own.
     *
     * @return ?array{0: ?string, 1: ?int}
     */
    private function readUpstream(User $user): ?array
    {
        $proxyTo = trim((string) ($this->option('proxy-to') ?? ''));
        if ($proxyTo === '') {
            return [null, null];
        }

        try {
            [$upstreamHost, $upstreamPort] = UpstreamSpec::parse($proxyTo, $user->username);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        $refusal = ProxyRuleUpstream::refusal('user', $user->username, $upstreamHost);
        if ($refusal !== null) {
            $this->error($refusal);

            return null;
        }

        return [$upstreamHost, $upstreamPort];
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
        if (! $user->domains()->getQuery()->where('domain', $parentDomain)->exists()) {
            return "Parent domain '{$parentDomain}' not found for project '{$project}'.";
        }
        if (! Str::endsWith($domainName, $parentDomain)) {
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

    /** @param CreateRequest $request */
    private function summarize(array $request): void
    {
        $proxyLabel = ($request['upstreamHost'] !== null && $request['upstreamPort'] !== null)
            ? "{$request['upstreamHost']}:{$request['upstreamPort']}"
            : '-';

        $this->line('');
        $this->info('Create domain');
        $table = new Table($this->output);
        $table->setRows([
            ['Domain', $request['domain']],
            ['Project', $request['user']->username],
            ['Type', $request['type']],
            ['Parent domain', $request['type'] === 'sub' ? $request['parent'] : '-'],
            ['Aliases', $request['aliases'] === [] ? '-' : implode("\n", $request['aliases'])],
            ['SSL', $request['noSsl'] ? 'disabled' : 'enabled'],
            ['Document root', "/{$request['domain']}/public_html"],
            ['Proxy to', $proxyLabel],
        ]);
        $table->render();
    }

    /**
     * Write the row, set the domain up and report it; a failure rolls it all back.
     *
     * @param  CreateRequest  $request
     */
    private function create(array $request): int
    {
        $domain = $this->createRow($request);

        try {
            $this->provision($request['user'], $domain, $request['upstreamHost'], $request['upstreamPort']);
        } catch (\Throwable $e) {
            $this->rollBack($domain);
            $this->error('Domain create failed: '.$e->getMessage());

            return 1;
        }

        $this->reportCreated($request['domain'], $request['upstreamHost'], $request['upstreamPort']);

        return 0;
    }

    /** @param CreateRequest $request */
    private function createRow(array $request): Domain
    {
        $parent = $request['type'] === 'sub' ? $request['parent'] : null;
        $details = NewDomain::details($request['domain'], $request['noSsl'], $request['aliases'], $parent);

        return Domain::create([
            'user_id' => $request['user']->id,
            'domain' => $request['domain'],
            'type' => $request['type'],
            'details' => $details,
        ]);
    }

    /** Set the domain up on the host and in the project's services, then proxy it when an upstream was given. */
    private function provision(User $user, Domain $domain, ?string $upstreamHost, ?int $upstreamPort): void
    {
        $domain->projectDomain()->create();
        $user->project()->syncPhpHandlersScripts();
        $user->project()->syncServices();

        if ($upstreamHost !== null && $upstreamPort !== null) {
            $this->proxyTo($user, $domain, $upstreamHost, $upstreamPort);
        }
    }

    /**
     * Undo create() as the REST path does, best-effort: deleting the row alone
     * would orphan its vhost, certificate and proxy rules.
     */
    private function rollBack(Domain $domain): void
    {
        try {
            $domain->projectDomain()->delete();
        } catch (\Throwable) {
        }
        try {
            $domain->delete();
        } catch (\Throwable) {
        }
    }

    private function reportCreated(string $domainName, ?string $upstreamHost, ?int $upstreamPort): void
    {
        $this->info("Domain '{$domainName}' created.");
        if ($upstreamHost !== null) {
            $this->line("Proxy rules set → {$upstreamHost}:{$upstreamPort} (listen 80/443).");
        }
        $this->line('');
    }

    /** Route the domain's 80 and 443 to the upstream, then rebuild it. */
    private function proxyTo(User $user, Domain $domain, string $upstreamHost, int $upstreamPort): void
    {
        foreach ([80, 443] as $listenPort) {
            $this->writeProxyRule($user, $domain->domain, $listenPort, $upstreamHost, $upstreamPort);
        }
        $domain->projectDomain()->rebuild();
        (new System)->webserver()->scheduleWebserverReloadInBackground();
    }

    private function writeProxyRule(User $user, string $domainName, int $listenPort, string $upstreamHost, int $upstreamPort): void
    {
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
}
