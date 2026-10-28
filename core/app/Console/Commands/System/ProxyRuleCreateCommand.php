<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\AppliesProxyRules;
use App\Console\Commands\Concerns\ConfirmsChanges;
use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\ProxyRule;
use App\Models\User;
use App\Rules\ListenIp;
use App\Rules\ProxyServerName;
use App\Rules\UpstreamHost;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;
use App\System\Services\Webserver\ProxyRuleServerName;
use App\System\Services\Webserver\ProxyRuleUpstream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProxyRuleCreateCommand extends Command
{
    use AppliesProxyRules;
    use ConfirmsChanges;
    use ResolvesProject;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['proxy-rule:create'];

    protected $signature = 'proxy:rule:create
        {--transport= : Transport type (http, tcp, udp) - interactive if not provided}
        {--listen-port= : Listen port - interactive if not provided}
        {--listen-ip= : Listen IP (default: *)}
        {--server-name= : Server name/hostname (for HTTP only); a user rule\'s is one of its project\'s domains, and no rule\'s is an alias or a traditional PHP project\'s domain}
        {--upstream-host= : Upstream host - interactive if not provided}
        {--upstream-port= : Upstream port - interactive if not provided}
        {--upstream-protocol=http : Upstream protocol (for HTTP: http, https; for stream: leave empty)}
        {--scope=system : Owner scope (system or user)}
        {--project= : Username (required for user-owned rules)} {--username= : Deprecated alias for --project}
        {--force : Skip confirmation}';

    protected $description = 'Create a new proxy rule';

    public function handle(): int
    {
        $this->foldProjectOption();

        $rule = $this->readRule();
        if ($rule === null) {
            return 1;
        }

        if ($this->refusedWithoutConfirmation()) {
            return 1;
        }

        $this->summarize($rule);

        if (! $this->option('force') && ! $this->confirm('Create this rule?')) {
            $this->info('Cancelled.');

            return 0;
        }

        if ($this->duplicateExists($rule)) {
            $this->error('A rule with this configuration already exists.');

            return 1;
        }

        $created = ProxyRule::create($rule + [
            'enabled' => true,
            'is_generated' => false,
            'metadata' => ['created_via' => 'artisan-command'],
        ]);

        $this->announce($created);
        $this->applyProxyRules();

        return 0;
    }

    private function announce(ProxyRule $created): void
    {
        $this->info("Rule created successfully (ID: {$created->id})");
        $warning = ProxyRuleServerName::defaultCertificateWarning($created);
        if ($warning !== null) {
            $this->warn($warning);
        }
    }

    /**
     * The rule the options and answers describe, or null once a refusal has
     * been printed.
     *
     * @return ?array{owner_scope: string, username: ?string, transport: string, listen_ip: string,
     *   listen_port: int, server_name: ?string, upstream_host: string, upstream_port: int,
     *   upstream_protocol: ?string}
     */
    private function readRule(): ?array
    {
        $scope = $this->scope();
        if ($scope === null) {
            return null;
        }

        $username = $this->username($scope);
        if ($scope === 'user' && ($username === null || ! User::query()->where('username', $username)->exists())) {
            $this->error('A user-owned rule needs an existing project (--project).');

            return null;
        }

        $transport = $this->transport();
        if ($transport === null) {
            return null;
        }

        $listen = $this->listen($transport);
        if ($listen === null) {
            return null;
        }

        $upstream = $this->upstream($transport);
        if ($upstream === null) {
            return null;
        }

        $rule = ['owner_scope' => $scope, 'username' => $username, 'transport' => $transport] + $listen + $upstream;

        return $this->invalidFields($rule) || $this->refused($rule) ? null : $rule;
    }

    private function scope(): ?string
    {
        $scope = $this->option('scope');
        if (! in_array($scope, ['system', 'user'])) {
            $this->error("Scope must be 'system' or 'user'.");

            return null;
        }
        assert(is_string($scope));

        return $scope;
    }

    /** The owning project from --project, asked for a user rule; null when none was given. */
    private function username(string $scope): ?string
    {
        $username = $this->option('username');
        if ($scope === 'user' && ! is_string($username)) {
            $username = $this->ask('Username (for user-owned rule)');
        }

        return is_string($username) && $username !== '' ? $username : null;
    }

    /** The transport, or null once refused: any other value is stored but never rendered, so the rule would silently do nothing. */
    private function transport(): ?string
    {
        $transport = $this->option('transport') ?: $this->choice(
            'Transport type',
            ['http', 'tcp', 'udp'],
            0
        );
        assert(is_string($transport));
        if (! in_array($transport, ['http', 'tcp', 'udp'], true)) {
            $this->error('The selected transport is invalid.');

            return null;
        }

        return $transport;
    }

    /** @return ?array{listen_ip: string, listen_port: int, server_name: ?string} */
    private function listen(string $transport): ?array
    {
        $listenPort = $this->port($this->option('listen-port') ?? $this->ask('Listen port (1-65535)'));
        if ($listenPort === null) {
            $this->error('Invalid port number.');

            return null;
        }

        return [
            'listen_ip' => $this->getListenIp(),
            'listen_port' => $listenPort,
            'server_name' => $transport === 'http' ? $this->serverName() : null,
        ];
    }

    /** @return ?array{upstream_host: string, upstream_port: int, upstream_protocol: ?string} */
    private function upstream(string $transport): ?array
    {
        $upstreamHost = $this->upstreamHost();
        if (! is_string($upstreamHost)) {
            $this->error('Invalid upstream host.');

            return null;
        }

        $upstreamPort = $this->port($this->option('upstream-port') ?? $this->ask('Upstream port (1-65535)'));
        if ($upstreamPort === null) {
            $this->error('Invalid upstream port number.');

            return null;
        }

        return [
            'upstream_host' => $upstreamHost,
            'upstream_port' => $upstreamPort,
            'upstream_protocol' => $transport === 'http' ? self::filled($this->option('upstream-protocol')) : null,
        ];
    }

    /**
     * Print every field error under the same rules as POST /proxy-rules, since
     * these values go into the shared proxy config verbatim.
     *
     * @param  array<string, mixed>  $rule
     */
    private function invalidFields(array $rule): bool
    {
        $validator = Validator::make(
            [
                'listen_ip' => $rule['listen_ip'],
                'server_name' => $rule['server_name'],
                'upstream_host' => $rule['upstream_host'],
                'upstream_protocol' => $rule['upstream_protocol'],
            ],
            [
                'listen_ip' => [new ListenIp],
                'server_name' => ['nullable', new ProxyServerName],
                'upstream_host' => [new UpstreamHost],
                'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            ]
        );
        if (! $validator->fails()) {
            return false;
        }

        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return true;
    }

    /**
     * Print why the owner may not use this upstream, server name or listen port, if it may not.
     *
     * @param  array<string, mixed>  $rule
     */
    private function refused(array $rule): bool
    {
        $scope = $rule['owner_scope'];
        $username = $rule['username'];
        $refusal = ProxyRuleUpstream::refusal($scope, $username, $rule['upstream_host'])
            ?? ProxyRuleServerName::refusal($scope, $username, $rule['server_name'])
            ?? (new ProxyListenPort(app(System::class)))->refusal($rule['transport'], $rule['listen_ip'], $rule['listen_port']);
        if ($refusal === null) {
            return false;
        }

        $this->error($refusal);

        return true;
    }

    protected function getListenIp(): string
    {
        $listenIp = $this->option('listen-ip');
        if (is_string($listenIp) && $listenIp !== '') {
            return $listenIp;
        }

        return '*';
    }

    /** A port 1-65535 as an int, or null for anything else. */
    private function port(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 65535) {
            return null;
        }

        return (int) $value;
    }

    /** The option, else the answer; null for a wildcard. */
    private function serverName(): ?string
    {
        return self::filled($this->option('server-name'))
            ?? self::filled($this->ask('Server name/hostname (or leave blank for wildcard)'));
    }

    /** The option, else a non-empty answer, else the option as it was. */
    private function upstreamHost(): mixed
    {
        $upstreamHost = $this->option('upstream-host');
        if (self::filled($upstreamHost) !== null) {
            return $upstreamHost;
        }

        return self::filled($this->ask('Upstream host')) ?? $upstreamHost;
    }

    /** The value when it is a non-empty string, otherwise null. */
    private static function filled(mixed $value): ?string
    {
        return ! empty($value) && is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $rule */
    private function summarize(array $rule): void
    {
        $scope = $rule['owner_scope'];
        $username = $rule['username'];
        $serverName = $rule['server_name'];
        $upstreamProtocol = $rule['upstream_protocol'];

        $this->info("\nNew Proxy Rule Summary:");
        $this->line("  Owner Scope: $scope".($username ? " ({$username})" : ''));
        $this->line("  Transport: {$rule['transport']}");
        $this->line("  Listen: {$rule['listen_ip']}:{$rule['listen_port']}".($serverName ? " [{$serverName}]" : ''));
        $this->line("  Upstream: {$rule['upstream_host']}:{$rule['upstream_port']}".($upstreamProtocol ? " ({$upstreamProtocol})" : ''));
    }

    /**
     * Whether a rule like this already exists. An HTTP name and port is one
     * vhost entry whatever the listen IP or owner, as the API's store checks;
     * a stream rule is judged per owner, transport, address and port.
     *
     * @param  array<string, mixed>  $rule
     */
    private function duplicateExists(array $rule): bool
    {
        if ($rule['transport'] === 'http') {
            return ProxyRule::conflictingHttpRule($rule['server_name'] ?? null, (int) $rule['listen_port']) !== null;
        }

        $existing = ProxyRule::query();
        $existing->where('transport', $rule['transport'])
            ->where('listen_port', $rule['listen_port'])
            ->where('listen_ip', $rule['listen_ip'])
            ->where('owner_scope', $rule['owner_scope']);

        if ($rule['username']) {
            $existing->where('username', $rule['username']);
        } else {
            $existing->whereNull('username');
        }

        return $existing->first() !== null;
    }
}
