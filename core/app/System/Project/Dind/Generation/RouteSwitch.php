<?php

namespace App\System\Project\Dind\Generation;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\System as EngineSystem;

/**
 * Moves the account's proxy rules between ports in its container, then one
 * graceful reload: requests in flight finish on the old upstream.
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
     * Enabled rules of every transport; an operator's own rules to these ports included.
     *
     * @param list<int> $ports
     * @return list<ProxyRule>
     */
    public function rulesTo(array $ports): array
    {
        return ProxyRule::query()
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
        if ($onlyIds === null) {
            $rules = $this->rulesTo(array_keys($map));
        } else {
            // Back by id, whatever its owner switched off meanwhile: it stays off, on its own port.
            $rules = $onlyIds === [] ? [] : ProxyRule::query()
                ->whereIn('id', $onlyIds)
                ->where('upstream_host', $this->username)
                ->whereIn('upstream_port', array_keys($map))
                ->get()
                ->all();
        }
        foreach ($rules as $rule) {
            $rule->upstream_port = $map[$rule->upstream_port];
            $rule->save();
        }
        $this->render($rules);

        return array_map(static fn (ProxyRule $rule): int => (int) $rule->id, $rules);
    }

    /**
     * The operator's own rules to the app's port, enabled or not, follow it
     * to the port the new version answers on. The ids moved.
     *
     * @return list<int>
     */
    public function follow(int $from, int $to): array
    {
        if ($from === $to) {
            return [];
        }
        $rules = ProxyRule::query()
            ->where('is_generated', false)
            ->where('upstream_host', $this->username)
            ->where('upstream_port', $from)
            ->get()
            ->all();

        return $this->move([$from => $to], array_map(static fn (ProxyRule $rule): int => (int) $rule->id, $rules));
    }

    /**
     * The operator's own rules to $port, enabled or not, as they are before a redeploy.
     *
     * @return list<array{id: int, port: int}>
     */
    public function handRulesTo(int $port): array
    {
        return array_map(static fn (ProxyRule $rule): array => ['id' => (int) $rule->id, 'port' => (int) $rule->upstream_port], ProxyRule::query()
            ->where('is_generated', false)
            ->where('upstream_host', $this->username)
            ->where('upstream_port', $port)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Each recorded rule back on its port, while it still points at this app
     * on one of $from: a rule its owner pointed elsewhere, or deleted, stays
     * as they left it. The ids moved.
     *
     * @param list<array{id: int, port: int}> $recorded
     * @param list<int> $from the ports a redeploy put them on
     * @return list<int>
     */
    public function putBack(array $recorded, array $from): array
    {
        $byPort = [];
        foreach ($recorded as $rule) {
            $byPort[$rule['port']][] = $rule['id'];
        }
        $moved = [];
        foreach ($byPort as $port => $ids) {
            $away = array_values(array_unique(array_filter($from, static fn (int $p): bool => $p > 0 && $p !== $port)));
            if ($away !== []) {
                $moved = [...$moved, ...$this->move(array_fill_keys($away, $port), $ids)];
            }
        }

        return $moved;
    }

    /**
     * A rule named after a domain lives in its vhost; any other is written by
     * the full rebuild, which also keeps the firewall open for its port.
     *
     * @param list<ProxyRule> $rules
     */
    private function render(array $rules): void
    {
        $live = array_values(array_filter($rules, static fn (ProxyRule $rule): bool => (bool) $rule->enabled));
        if ($live === []) {
            return;
        }
        $inVhost = static fn (ProxyRule $rule): bool => $rule->transport === 'http'
            && !in_array(trim((string) $rule->server_name), ['', '_'], true);
        $webserver = $this->system->webserver();
        if (count(array_filter($live, $inVhost)) === count($live)) {
            foreach (self::domainsOf($live) as $domain) {
                $webserver->rebuildDomainConfig($domain);
            }
        } else {
            $webserver->rebuildConfig();
        }
        $webserver->reload(false);
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
