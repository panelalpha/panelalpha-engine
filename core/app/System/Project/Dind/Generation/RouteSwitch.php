<?php

namespace App\System\Project\Dind\Generation;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\System as EngineSystem;

/**
 * Moves the account's HTTP proxy rules between ports in its container, then
 * one graceful reload: requests in flight finish on the old upstream.
 */
final class RouteSwitch
{
    public const WEBSERVER = 'nginx-proxy';

    public function __construct(
        private readonly EngineSystem $system,
        private readonly string $username,
    ) {
    }

    /**
     * @param list<int> $ports
     * @return list<ProxyRule>
     */
    public function httpRulesTo(array $ports): array
    {
        return ProxyRule::query()
            ->where('transport', 'http')
            ->where('enabled', true)
            ->where('upstream_host', $this->username)
            ->whereIn('upstream_port', $ports)
            ->get()
            ->all();
    }

    /**
     * Host ports published through TCP/UDP rules, which a vhost reload does not move.
     *
     * @param list<int> $ports
     * @return list<ProxyRule>
     */
    public function streamRulesTo(array $ports): array
    {
        return ProxyRule::query()
            ->whereIn('transport', ['tcp', 'udp'])
            ->where('enabled', true)
            ->where('upstream_host', $this->username)
            ->whereIn('upstream_port', $ports)
            ->get()
            ->all();
    }

    /**
     * A domain a Cloudflare tunnel serves: its ingress names the account's
     * port itself. A PanelAlpha Online name only points DNS at this host.
     *
     * @param list<ProxyRule> $rules
     */
    public static function tunnelledDomain(array $rules): ?string
    {
        foreach (self::domainsOf($rules) as $domain) {
            $cloudflare = Tunnel::query()
                ->where('domain_id', $domain->id)
                ->where('provider', Tunnel::PROVIDER_CLOUDFLARE)
                ->exists();
            if ($cloudflare) {
                return (string) $domain->domain;
            }
        }

        return null;
    }

    /**
     * The ids of the rules moved from each key of $map to its value.
     *
     * @param array<int, int> $map
     * @param ?list<int> $onlyIds
     * @return list<int>
     */
    public function move(array $map, ?array $onlyIds = null): array
    {
        $rules = $this->httpRulesTo(array_keys($map));
        if ($onlyIds !== null) {
            $rules = array_values(array_filter($rules, static fn (ProxyRule $rule): bool => in_array($rule->id, $onlyIds, true)));
        }
        if ($rules === []) {
            return [];
        }
        foreach ($rules as $rule) {
            $rule->upstream_port = $map[$rule->upstream_port];
            $rule->save();
        }
        foreach (self::domainsOf($rules) as $domain) {
            $this->system->webserver()->rebuildDomainConfig($domain);
        }
        $this->system->webserver()->reload(false);

        return array_map(static fn (ProxyRule $rule): int => (int) $rule->id, $rules);
    }

    /**
     * @param list<ProxyRule> $rules
     * @return list<Domain>
     */
    private static function domainsOf(array $rules): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (ProxyRule $rule): string => trim((string) $rule->server_name),
            $rules
        ))));
        if ($names === []) {
            return [];
        }

        return Domain::query()->whereIn('domain', $names)->get()->all();
    }
}
