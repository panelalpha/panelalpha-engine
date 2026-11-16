<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Log;

/**
 * Nginx-proxy routing: each domain's generated defaults, minus those a stored custom rule claims, plus those rules,
 * as separate HTTP and stream rule sets.
 *
 * @psalm-type HttpRule = array{
 *   id: int,
 *   transport: string,
 *   listen_ip: string,
 *   listen_port: int,
 *   server_name: string,
 *   server_names: array<string>,
 *   upstream_host: string,
 *   upstream_port: int,
 *   upstream_protocol: string,
 *   ssl_enabled: bool,
 *   ssl_cert_pem_file: ?string,
 *   ssl_cert_key_file: ?string,
 *   user: string,
 *   domain: string,
 *   is_generated: bool,
 *   conflict_key: string
 * }
 * @psalm-type StreamRule = array{
 *   id: int,
 *   transport: string,
 *   listen_ip: string,
 *   listen_port: int,
 *   upstream_host: string,
 *   upstream_port: int,
 *   is_generated: bool
 * }
 */
class RoutingCompiler
{
    /** @var array<ProxyRule> */
    private array $persistedRules = [];

    /** @psalm-var list<HttpRule> */
    private array $finalHttpRules = [];

    /** @psalm-var list<StreamRule> */
    private array $finalStreamRules = [];

    public function __construct(
        private readonly EngineSystem $system,
    ) {
        $this->loadPersistedRules();
    }

    /** A rule stored before its upstream and server name were checked is neither served nor given a port. */
    private function loadPersistedRules(): void
    {
        $owners = ProxyRuleServerName::owners();
        $this->persistedRules = array_values(array_filter(ProxyRule::getEnabled(), static function (ProxyRule $rule) use ($owners): bool {
            if (! ProxyRuleUpstream::serves($rule)) {
                Log::warning("Proxy rule {$rule->id} of {$rule->username} is not served: "
                    ."its upstream {$rule->upstream_host} is not the project's own app.");

                return false;
            }
            if (! ProxyRuleServerName::serves($rule, $owners)) {
                Log::warning("Proxy rule {$rule->id} of {$rule->username} is not served: "
                    ."its server name {$rule->server_name} is not one of the project's own domains.");

                return false;
            }

            return true;
        }));
    }

    /**
     * Compile routing rules from domains and persisted rules.
     *
     * @param  array<Domain>  $domains
     * @return array{http: array<array>, stream: array<array>}
     *
     * @psalm-return array{http: list<HttpRule>, stream: list<StreamRule>}
     */
    public function compile(array $domains): array
    {
        foreach ($domains as $domain) {
            $this->generateDomainRules($domain);
        }

        $this->suppressConflictingDefaults();

        return [
            'http' => $this->finalHttpRules,
            'stream' => $this->finalStreamRules,
        ];
    }

    /**
     * Generate HTTP vhost rules from a domain model.
     */
    private function generateDomainRules(Domain $domain): void
    {
        $user = $domain->getUser();
        $appPort = $user->getAppPort() ?? 80;

        $primaryHostname = $domain->domain;
        $aliases = $domain->getVhostAltNames();
        $allHostnames = [
            $primaryHostname,
            ...$aliases,
        ];

        $port80Rule = $this->port80Rule($user, $primaryHostname, $allHostnames, $appPort);
        $this->finalHttpRules[] = $port80Rule;

        if ($domain->sslEnabled()) {
            $port443Rule = $this->port443Rule($user, $primaryHostname, $allHostnames, $appPort);
            $this->finalHttpRules[] = $port443Rule;
        }
    }

    /**
     * @param  array<string>  $allHostnames
     *
     * @psalm-return HttpRule
     */
    private function port80Rule(User $user, string $primaryHostname, array $allHostnames, int $appPort): array
    {
        return [
            'id' => 0,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => $primaryHostname,
            'server_names' => $allHostnames,
            'upstream_host' => 'localhost',
            'upstream_port' => $appPort,
            'upstream_protocol' => 'http',
            'ssl_enabled' => false,
            'ssl_cert_pem_file' => null,
            'ssl_cert_key_file' => null,
            'user' => $user->username,
            'domain' => $primaryHostname,
            'is_generated' => true,
            'conflict_key' => $this->buildConflictKey('http', '*', 80, null),
        ];
    }

    /**
     * @param  array<string>  $allHostnames
     *
     * @psalm-return HttpRule
     */
    private function port443Rule(User $user, string $primaryHostname, array $allHostnames, int $appPort): array
    {
        return [
            'id' => 0,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 443,
            'server_name' => $primaryHostname,
            'server_names' => $allHostnames,
            'upstream_host' => 'localhost',
            'upstream_port' => $appPort,
            'upstream_protocol' => 'http',
            'ssl_enabled' => true,
            'ssl_cert_pem_file' => "{$this->system->project($user)->projectDirPath()}/ssl-certs/{$primaryHostname}.pem",
            'ssl_cert_key_file' => "{$this->system->project($user)->projectDirPath()}/ssl-certs/{$primaryHostname}.key",
            'user' => $user->username,
            'domain' => $primaryHostname,
            'is_generated' => true,
            'conflict_key' => $this->buildConflictKey('http', '*', 443, null),
        ];
    }

    /**
     * Drops the generated rules each persisted http rule claims, then adds the persisted rules. Only http defaults
     * are generated, so a tcp/udp rule has nothing to suppress ({@see RoutingCompilerTest::test_no_generated_stream_rules_exist_to_suppress}).
     */
    private function suppressConflictingDefaults(): void
    {
        foreach ($this->persistedRules as $persistedRule) {
            if ($persistedRule->transport === 'http') {
                $this->finalHttpRules = $this->httpRulesNotClaimedBy($persistedRule);
            }
        }

        foreach ($this->persistedRules as $persistedRule) {
            if ($persistedRule['transport'] === 'http') {
                $this->finalHttpRules[] = $this->persistedHttpRule($persistedRule);
            } elseif (in_array($persistedRule['transport'], ['tcp', 'udp'])) {
                $this->finalStreamRules[] = $this->persistedStreamRule($persistedRule);
            }
        }
    }

    /**
     * The rules on another port or IP than $persistedRule, and, when it names a server, those whose names miss it.
     *
     * @psalm-return list<HttpRule>
     */
    private function httpRulesNotClaimedBy(ProxyRule $persistedRule): array
    {
        $filteredHttpRules = [];
        foreach ($this->finalHttpRules as $rule) {
            if (
                $rule['listen_port'] !== $persistedRule->listen_port ||
                $rule['listen_ip'] !== $persistedRule->listen_ip
            ) {
                $filteredHttpRules[] = $rule;

                continue;
            }
            if (! empty($persistedRule->server_name)) {
                if (! in_array($persistedRule->server_name, $rule['server_names'] ?? [])) {
                    $filteredHttpRules[] = $rule;

                    continue;
                }
            }
        }

        return $filteredHttpRules;
    }

    /** @psalm-return HttpRule */
    private function persistedHttpRule(ProxyRule $persistedRule): array
    {
        return [
            'id' => $persistedRule->id,
            'transport' => $persistedRule->transport,
            'listen_ip' => $persistedRule->listen_ip ?? '*',
            'listen_port' => $persistedRule->listen_port,
            'server_name' => $persistedRule->server_name ?? '_',
            'server_names' => [],
            'upstream_host' => $persistedRule->upstream_host,
            'upstream_port' => $persistedRule->upstream_port,
            'upstream_protocol' => $persistedRule->upstream_protocol ?? '',
            'ssl_enabled' => false,
            'ssl_cert_pem_file' => null,
            'ssl_cert_key_file' => null,
            'user' => $persistedRule->username ?? '',
            'domain' => $persistedRule->user?->domain ?? '',
            'is_generated' => $persistedRule->is_generated,
            'conflict_key' => $this->buildConflictKey($persistedRule->transport, $persistedRule->listen_ip ?? '*', $persistedRule->listen_port, null),
        ];
    }

    /** @psalm-return StreamRule */
    private function persistedStreamRule(ProxyRule $persistedRule): array
    {
        return [
            'id' => $persistedRule->id,
            'transport' => $persistedRule->transport,
            'listen_ip' => $persistedRule->listen_ip ?? '*',
            'listen_port' => $persistedRule->listen_port,
            'upstream_host' => $persistedRule->upstream_host,
            'upstream_port' => $persistedRule->upstream_port,
            'is_generated' => $persistedRule->is_generated,
        ];
    }

    /**
     * Build conflict key for conflict detection.
     */
    private function buildConflictKey(string $transport, ?string $listenIp, int $listenPort, ?string $serverName = null): string
    {
        if ($transport === 'http') {
            return implode(':', [
                'http',
                $listenIp ?? '*',
                $listenPort,
                $serverName ?? '*',
            ]);
        }

        return implode(':', [
            $transport,
            $listenIp ?? '*',
            $listenPort,
        ]);
    }
}
