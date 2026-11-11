<?php

namespace App\Lib\Project\PublicRouting;

use App\Models\ProxyRule;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;
use App\System\Services\Webserver\ProxyRuleDuplicate;

/**
 * The tcp/udp proxy rules a project's `public_ports:` asks for, kept in line with the file: rules the file no
 * longer lists go, a port someone else holds is skipped. The project's hand-made rules are never touched.
 *
 * @psalm-type PortState = array{listen: int, to: int, transport: string, state: string, reason?: string, rule_id?: int}
 */
class PublicPortRules
{
    /** `metadata.source` of the rules made from the file. */
    public const SOURCE = 'panelalpha.yaml';

    public const OPEN = 'open';

    public const REFUSED = 'refused';

    public const NOT_ALLOWED = 'not_allowed';

    public function __construct(
        private readonly System $system,
        private readonly PublicRoutingGrants $grants,
    ) {}

    /**
     * @return list<PortState>
     */
    public function apply(string $username, PublicRouting $routing): array
    {
        $allowed = $routing->ports !== [] && $this->grants->allows($username, PublicRoutingGrants::PORTS);
        $existing = self::declaredRules($username);
        $states = [];
        $kept = [];
        $changed = false;

        foreach ($routing->ports as $port) {
            if (! $allowed) {
                $states[] = self::state($port, self::NOT_ALLOWED, 'the operator has not allowed this project public ports');

                continue;
            }
            $rule = $existing[$port->key()] ?? null;
            $refusal = $this->refusal($port, $rule);
            if ($refusal !== null) {
                $states[] = self::state($port, self::REFUSED, $refusal);

                continue;
            }
            $saved = self::save($username, $port, $rule);
            $changed = $changed || $saved->wasRecentlyCreated || $saved->wasChanged();
            $kept[] = (int) $saved->id;
            $states[] = self::state($port, self::OPEN) + ['rule_id' => (int) $saved->id];
        }

        $removed = self::removeOthers($existing, $kept);
        if ($changed || $removed > 0) {
            $this->rebuildProxyConfig();
        }

        return $states;
    }

    /** Whether a rule was made from the file, and is the file's to change. */
    public static function isDeclared(ProxyRule $rule): bool
    {
        return ($rule->metadata['source'] ?? null) === self::SOURCE;
    }

    /** @return array<string, ProxyRule> by {@see PublicPort::key()} */
    private static function declaredRules(string $username): array
    {
        $rules = [];
        foreach (ProxyRule::forUser($username)->whereIn('transport', PublicPort::TRANSPORTS)->get() as $rule) {
            if (self::isDeclared($rule)) {
                $rules[$rule->transport.'/'.$rule->listen_port] = $rule;
            }
        }

        return $rules;
    }

    private function refusal(PublicPort $port, ?ProxyRule $rule): ?string
    {
        if ($rule !== null && $rule->enabled) {
            return ProxyRuleDuplicate::ruleRefusal($port->transport, '*', $port->listen, null, (int) $rule->id);
        }

        return (new ProxyListenPort($this->system))->refusal($port->transport, '*', $port->listen)
            ?? ProxyRuleDuplicate::ruleRefusal($port->transport, '*', $port->listen, null, $rule?->id);
    }

    /**
     * A rule whose `to` is unchanged keeps its upstream port: a redeploy beside the running version moves it.
     */
    private static function save(string $username, PublicPort $port, ?ProxyRule $rule): ProxyRule
    {
        $rule ??= new ProxyRule([
            'owner_scope' => 'user',
            'username' => $username,
            'transport' => $port->transport,
            'listen_ip' => '*',
            'listen_port' => $port->listen,
            'upstream_host' => $username,
            'is_generated' => false,
        ]);
        if (! $rule->exists || ($rule->metadata['to'] ?? null) !== $port->to) {
            $rule->upstream_port = $port->to;
        }
        $rule->enabled = true;
        $rule->metadata = [
            'source' => self::SOURCE,
            'to' => $port->to,
            'description' => "{$port->transport} {$port->listen} → {$username}:{$port->to}, from panelalpha.yaml",
        ];
        $rule->save();

        return $rule;
    }

    /**
     * @param  array<string, ProxyRule>  $existing
     * @param  list<int>  $kept
     */
    private static function removeOthers(array $existing, array $kept): int
    {
        $removed = 0;
        foreach ($existing as $rule) {
            if (! in_array((int) $rule->id, $kept, true)) {
                $rule->delete();
                $removed++;
            }
        }

        return $removed;
    }

    /** @return PortState */
    private static function state(PublicPort $port, string $state, ?string $reason = null): array
    {
        return $port->toArray() + ['state' => $state] + ($reason === null ? [] : ['reason' => $reason]);
    }

    private function rebuildProxyConfig(): void
    {
        $this->system->webserver()->rebuildConfig();
        $this->system->webserver()->scheduleWebserverReloadInBackground();
    }
}
