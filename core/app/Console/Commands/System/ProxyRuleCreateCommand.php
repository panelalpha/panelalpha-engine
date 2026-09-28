<?php

namespace App\Console\Commands\System;

use App\Models\ProxyRule;
use App\Console\Commands\Concerns\ResolvesProject;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class ProxyRuleCreateCommand extends Command
{
    use ResolvesProject;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['proxy-rule:create'];

    protected $signature = 'proxy:rule:create
        {--transport= : Transport type (http, tcp, udp) - interactive if not provided}
        {--listen-port= : Listen port - interactive if not provided}
        {--listen-ip=* : Listen IP (default: *)}
        {--server-name= : Server name/hostname (for HTTP only)}
        {--upstream-host= : Upstream host - interactive if not provided}
        {--upstream-port= : Upstream port - interactive if not provided}
        {--upstream-protocol=http : Upstream protocol (for HTTP: http, https; for stream: leave empty)}
        {--scope=system : Owner scope (system or user)}
        {--project= : Username (required for user-owned rules)} {--username= : Deprecated alias for --project}
        {--force : Skip confirmation}';

    protected function getListenIp(): string
    {
        $listenIp = $this->option('listen-ip');
        if (is_string($listenIp)) {
            return $listenIp;
        }
        return '*';
    }

    protected $description = 'Create a new proxy rule';

    public function handle(): int
    {
        $this->foldProjectOption();

        $rule = $this->readRule();
        if ($rule === null) {
            return 1;
        }

        $this->summarize($rule);

        if (!$this->option('force') && !$this->confirm('Create this rule?')) {
            $this->info('Cancelled.');
            return 0;
        }

        if ($this->duplicateExists($rule)) {
            $this->error('A rule with this configuration already exists.');
            return 1;
        }

        /** @var ProxyRule */
        $created = ProxyRule::create($rule + [
            'enabled' => true,
            'is_generated' => false,
            'metadata' => ['created_via' => 'artisan-command'],
        ]);

        $this->info("Rule created successfully (ID: {$created->id})");
        return 0;
    }

    /**
     * The rule the options and answers describe, or null once a refusal has
     * been printed.
     *
     * @return ?array{owner_scope: string, username: string, transport: string, listen_ip: string,
     *   listen_port: int, server_name: ?string, upstream_host: string, upstream_port: int,
     *   upstream_protocol: ?string}
     */
    private function readRule(): ?array
    {
        $scope = $this->option('scope');
        if (!in_array($scope, ['system', 'user'])) {
            $this->error("Scope must be 'system' or 'user'.");
            return null;
        }
        assert(is_string($scope));

        $username = $this->option('username');
        if ($scope === 'user' && !is_string($username)) {
            /** @var mixed $username */
            $username = $this->ask('Username (for user-owned rule)');
        }
        assert(is_string($username));

        $transport = $this->option('transport') ?: $this->choice(
            'Transport type',
            ['http', 'tcp', 'udp'],
            0
        );
        assert(is_string($transport));

        $listenPort = $this->port($this->option('listen-port') ?: $this->ask('Listen port (1-65535)'));
        if ($listenPort === null) {
            $this->error('Invalid port number.');
            return null;
        }

        $listenIp = $this->getListenIp();
        $serverName = $transport === 'http' ? $this->serverName() : null;

        $upstreamHost = $this->upstreamHost();
        if (!is_string($upstreamHost)) {
            $this->error('Invalid upstream host.');
            return null;
        }

        $upstreamPort = $this->port($this->option('upstream-port') ?: $this->ask('Upstream port (1-65535)'));
        if ($upstreamPort === null) {
            $this->error('Invalid upstream port number.');
            return null;
        }

        $upstreamProtocol = null;
        if ($transport === 'http') {
            $upstreamProtocolOption = $this->option('upstream-protocol');
            if (!empty($upstreamProtocolOption) && is_string($upstreamProtocolOption)) {
                $upstreamProtocol = $upstreamProtocolOption;
            }
        }

        return [
            'owner_scope' => $scope,
            'username' => $username,
            'transport' => $transport,
            'listen_ip' => $listenIp,
            'listen_port' => $listenPort,
            'server_name' => $serverName,
            'upstream_host' => $upstreamHost,
            'upstream_port' => $upstreamPort,
            'upstream_protocol' => $upstreamProtocol,
        ];
    }

    /** A port 1-65535 as an int, or null for anything else. */
    private function port(mixed $value): ?int
    {
        if (!is_numeric($value) || (int)$value < 1 || (int)$value > 65535) {
            return null;
        }

        return (int)$value;
    }

    /** The option, else the answer; null for a wildcard. */
    private function serverName(): ?string
    {
        $serverName = null;
        $serverNameOption = $this->option('server-name');
        if (!empty($serverNameOption) && is_string($serverNameOption)) {
            $serverName = $serverNameOption;
        }
        if (empty($serverName)) {
            /** @var mixed $serverNameAsk */
            $serverNameAsk = $this->ask('Server name/hostname (or leave blank for wildcard)');
            if (!empty($serverNameAsk) && is_string($serverNameAsk)) {
                $serverName = $serverNameAsk;
            }
        }

        return $serverName;
    }

    private function upstreamHost(): mixed
    {
        $upstreamHost = $this->option('upstream-host');
        if (empty($upstreamHost) || !is_string($upstreamHost)) {
            /** @var mixed */
            $upstreamHostAsk = $this->ask('Upstream host');
            if (!empty($upstreamHostAsk) && is_string($upstreamHostAsk)) {
                $upstreamHost = $upstreamHostAsk;
            }
        }

        return $upstreamHost;
    }

    /** @param array<string, mixed> $rule */
    private function summarize(array $rule): void
    {
        $scope = $rule['owner_scope'];
        $username = $rule['username'];
        $serverName = $rule['server_name'];
        $upstreamProtocol = $rule['upstream_protocol'];

        $this->info("\nNew Proxy Rule Summary:");
        $this->line("  Owner Scope: $scope" . ($username ? " ({$username})" : ''));
        $this->line("  Transport: {$rule['transport']}");
        $this->line("  Listen: {$rule['listen_ip']}:{$rule['listen_port']}" . ($serverName ? " [{$serverName}]" : ''));
        $this->line("  Upstream: {$rule['upstream_host']}:{$rule['upstream_port']}" . ($upstreamProtocol ? " ({$upstreamProtocol})" : ''));
    }

    /**
     * Whether this owner already has a rule on the same transport, address
     * and port (and, for http, the same server name or the same wildcard).
     *
     * @param array<string, mixed> $rule
     */
    private function duplicateExists(array $rule): bool
    {
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

        if ($rule['transport'] === 'http' && $rule['server_name']) {
            $existing->where('server_name', $rule['server_name']);
        } elseif ($rule['transport'] === 'http') {
            $existing->whereNull('server_name');
        }

        return $existing->first() !== null;
    }
}
