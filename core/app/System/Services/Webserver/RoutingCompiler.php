<?php

namespace App\System\Services\Webserver;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\System as EngineSystem;
use Illuminate\Support\Facades\Log;

/**
 * Nginx-proxy routing compiler.
 * 
 * Merges persisted custom rules with auto-generated domain defaults,
 * implements conflict suppression, and maintains separate HTTP and stream rule sets.
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
    private array $generatedRules = [];
    /** @psalm-var list<HttpRule> */
    private array $finalHttpRules = [];
    /** @psalm-var list<StreamRule> */
    private array $finalStreamRules = [];

    public function __construct(
        private readonly EngineSystem $system,
    ) {
        $this->loadPersistedRules();
    }

    private function loadPersistedRules(): void
    {
        $owners = ProxyRuleServerName::owners();
        $this->persistedRules = array_values(array_filter(ProxyRule::getEnabled(), static function (ProxyRule $rule) use ($owners): bool {
            // Stored before these were checked: neither served nor given a port.
            if (!ProxyRuleUpstream::serves($rule)) {
                Log::warning("Proxy rule {$rule->id} of {$rule->username} is not served: "
                    . "its upstream {$rule->upstream_host} is not the project's own app.");
                return false;
            }
            if (!ProxyRuleServerName::serves($rule, $owners)) {
                Log::warning("Proxy rule {$rule->id} of {$rule->username} is not served: "
                    . "its server name {$rule->server_name} is not one of the project's own domains.");
                return false;
            }

            return true;
        }));
    }

    /**
     * Compile routing rules from domains and persisted rules.
     * 
     * @param array<Domain> $domains
     * @return array{http: array<array>, stream: array<array>}
     * @psalm-return array{http: list<HttpRule>, stream: list<StreamRule>}
     */
    public function compile(array $domains): array
    {
        // Generate default rules from domains
        foreach ($domains as $domain) {
            $this->generateDomainRules($domain);
        }

        // Suppress conflicting generated defaults where custom rules exist
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
            ...$aliases
        ];

        // Create rules for the primary domain and all aliases
        // Port 80 (HTTP) rule
        $port80Rule = [
            'id' => 0,
            'transport' => 'http',
            'listen_ip' => '*',
            'listen_port' => 80,
            'server_name' => $primaryHostname,
            'server_names' => $allHostnames,  // Multiple Hostnames
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
        $this->generatedRules[] = $port80Rule;
        $this->finalHttpRules[] = $port80Rule;

        // Port 443 (HTTPS) rule if SSL is enabled
        if ($domain->sslEnabled()) {
            $port443Rule = [
                'id' => 0,
                'transport' => 'http',
                'listen_ip' => '*',
                'listen_port' => 443,
                'server_name' => $primaryHostname,
                'server_names' => $allHostnames,  // Multiple Hostnames
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
            $this->generatedRules[] = $port443Rule;
            $this->finalHttpRules[] = $port443Rule;
        }
    }

    /**
     * Suppress generated rules that conflict with persisted custom rules.
     */
    private function suppressConflictingDefaults(): void
    {
        // For each persisted rule, remove conflicting generated rules
        foreach ($this->persistedRules as $persistedRule) {
            $conflictKey = $this->buildConflictKeyFromRule($persistedRule);

            if ($persistedRule->transport === 'http') {
                // For HTTP: remove generated rules with same port but any hostname
                // (unless it's a specific domain override, not port-only)
                $filteredHttpRules = [];
                foreach ($this->finalHttpRules as $rule) {
                    // Keep if: different port, different IP, or this rule is not generated
                    if (
                        $rule['listen_port'] !== $persistedRule->listen_port ||
                        $rule['listen_ip'] !== $persistedRule->listen_ip
                    ) {
                        $filteredHttpRules[] = $rule;
                        continue;
                    }
                    // If persisted rule has specific server_name, only suppress matching server_names
                    if (!empty($persistedRule->server_name)) {
                        // Only suppress if generated rule's server_names include this server_name
                        if (!in_array($persistedRule->server_name, $rule['server_names'] ?? [])) {
                            $filteredHttpRules[] = $rule;
                            continue;
                        }
                    }
                }
                $this->finalHttpRules = $filteredHttpRules;
            }

            // No stream branch: generateDomainRules() only ever produces http
            // defaults, so at this point finalStreamRules is always empty and
            // there is nothing for a tcp/udp rule to suppress. There used to be
            // a branch here that filtered it into a local and then dropped the
            // result on the floor -- dead either way, but misleading.
            // {@see RoutingCompilerTest::test_no_generated_stream_rules_exist_to_suppress}
        }

        // Add persisted rules to final rule sets
        foreach ($this->persistedRules as $persistedRule) {
            if ($persistedRule['transport'] === 'http') {
                $this->finalHttpRules[] = [
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
            } elseif (in_array($persistedRule['transport'], ['tcp', 'udp'])) {
                $this->finalStreamRules[] = [
                    'id' => $persistedRule->id,
                    'transport' => $persistedRule->transport,
                    'listen_ip' => $persistedRule->listen_ip ?? '*',
                    'listen_port' => $persistedRule->listen_port,
                    'upstream_host' => $persistedRule->upstream_host,
                    'upstream_port' => $persistedRule->upstream_port,
                    'is_generated' => $persistedRule->is_generated,
                ];
            }
        }
    }

    /**
     * Build conflict key for a persisted rule from the database record.
     */
    private function buildConflictKeyFromRule(ProxyRule $rule): string
    {
        if ($rule->transport === 'http') {
            return implode(':', [
                'http',
                $rule->listen_ip ?? '*',
                $rule->listen_port,
                $rule->server_name ?? '*',
            ]);
        }

        return implode(':', [
            $rule->transport,
            $rule->listen_ip ?? '*',
            $rule->listen_port,
        ]);
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
