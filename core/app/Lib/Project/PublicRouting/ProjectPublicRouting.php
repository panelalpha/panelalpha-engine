<?php

namespace App\Lib\Project\PublicRouting;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\Compose\DeclaredPortBinding;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Models\Domain;
use App\Models\User;

/**
 * A project's `domain:` and `public_ports:` applied: the server's name taken or given back before the build, the
 * ports after the domain's own rules. What came of it is kept on the project for its resource and the CLI.
 *
 * @psalm-import-type DomainState from HostDomain
 * @psalm-import-type PortState from PublicPortRules
 */
class ProjectPublicRouting
{
    public const DETAIL = 'public_routing';

    public function __construct(
        private readonly HostDomain $hostDomain,
        private readonly PublicPortRules $ports,
        private readonly PublicRoutingGrants $grants,
        private readonly HttpsPortsChange $httpsPorts,
    ) {}

    /**
     * What the project's last deploy asked for, and what came of it.
     *
     * @return array{declared?: array<string, mixed>, domain?: DomainState, ports?: list<PortState>}
     */
    public static function recorded(User $user): array
    {
        $recorded = $user->getDetails()[self::DETAIL] ?? null;

        return is_array($recorded) ? $recorded : [];
    }

    public static function declared(User $user): PublicRouting
    {
        return PublicRouting::fromArray(self::recorded($user)['declared'] ?? null);
    }

    /**
     * The project resource's `public_routing`: null for a project whose last deploy asked for nothing.
     *
     * @return ?array{allowed: list<string>, asked: array<string, mixed>, domain: ?array<string, mixed>, ports: list<PortState>}
     */
    public static function view(User $user, PublicRoutingGrants $grants): ?array
    {
        $recorded = self::recorded($user);
        $declared = self::declared($user);
        if ($declared->isEmpty() && ($recorded['ports'] ?? []) === [] && ($recorded['domain']['state'] ?? null) !== HostDomain::ON_HOST) {
            return null;
        }
        $allowed = $grants->of((string) $user->username);
        $current = HostDomain::current($user, is_array($recorded['domain'] ?? null) ? $recorded['domain'] : null);
        $domain = $current === null ? null : array_diff_key($current, ['previous' => true]);

        return [
            'allowed' => $allowed,
            'asked' => $declared->toArray(),
            'domain' => $domain,
            'ports' => $recorded['ports'] ?? [],
        ];
    }

    /** Before the build, so the application is built for the name it will answer on. */
    public function prepare(User $user, PublicRouting $routing, ?DeployLogger $logger = null): void
    {
        if ($routing->isEmpty() && self::recorded($user) === []) {
            return;
        }
        $before = self::recorded($user)['domain'] ?? null;
        $domain = $this->hostDomain->apply($user, $routing, $before);
        $this->record($user, ['declared' => $routing->toArray(), 'domain' => $domain]);
        $this->logDomain($routing, $domain, $before, $logger);
    }

    /** After the domain's own rules: the ports as the file asks and the operator allows. */
    public function routePorts(User $user, ?DeployLogger $logger = null): void
    {
        $states = $this->refreshPorts($user);
        foreach ($states as $state) {
            $this->logPort($state, $logger);
        }
    }

    /**
     * The ports again from what the last deploy declared: after the operator allowed or denied them.
     *
     * @return list<PortState>
     */
    public function refreshPorts(User $user): array
    {
        $declared = self::declared($user);
        $before = self::recorded($user)['ports'] ?? [];
        if ($declared->ports === [] && $before === []) {
            return [];
        }
        $states = $this->ports->apply($user->username, $declared);
        $this->record($user, ['ports' => $states]);

        return $states;
    }

    /**
     * The operator lets the project have $kinds. Ports open at once from what its last deploy declared; the server's name
     * is taken at its next deploy, which builds the application for that name.
     *
     * @param  list<string>  $kinds
     * @return array{allowed: list<string>, ports: list<PortState>}
     */
    public function allow(User $user, array $kinds): array
    {
        $allowed = $this->grants->allow($user->username, $kinds);
        $ports = in_array(PublicRoutingGrants::PORTS, $kinds, true) ? $this->refreshPorts($user) : [];

        return ['allowed' => $allowed, 'ports' => $ports];
    }

    /**
     * The operator takes $kinds away at once: the ports close and the project leaves the server's name for `moved_to`,
     * which its application is redeployed to use. `changed` is false when the project had none of it.
     *
     * @param  list<string>  $kinds
     * @return array{allowed: list<string>, changed: bool, ports: list<PortState>, moved_to: ?string, https_closed: list<string>}
     *
     * @throws DeployAlreadyRunningException while a deploy may be applying what is being taken away
     */
    public function deny(User $user, array $kinds): array
    {
        $lock = DeployLock::acquireFor($user->username);
        try {
            $before = $this->footprint($user);
            $allowed = $this->grants->deny($user->username, $kinds);
            $denyPorts = in_array(PublicRoutingGrants::PORTS, $kinds, true);
            $ports = $denyPorts ? $this->refreshPorts($user) : [];
            $httpsClosed = $denyPorts ? $this->closeHttpsPorts($user) : [];
            if (in_array(PublicRoutingGrants::HOST_DOMAIN, $kinds, true)) {
                $this->releaseHostDomain($user);
            }
            $changed = $this->footprint($user) !== $before;
        } finally {
            $lock->release();
        }

        return [
            'allowed' => $allowed,
            'changed' => $changed,
            'ports' => $ports,
            'moved_to' => $user->domain !== $before['main_domain'] ? (string) $user->domain : null,
            'https_closed' => $httpsClosed,
        ];
    }

    /**
     * What a deny can take away: the grants, the ports' states, the main domain, and every domain with the ports it
     * answers HTTPS on.
     *
     * @return array{allowed: list<string>, ports: mixed, main_domain: ?string, domains: array<string, list<int>>}
     */
    private function footprint(User $user): array
    {
        $domains = [];
        foreach ($user->domains()->getQuery()->get() as $domain) {
            /** @var Domain $domain */
            $domains[(string) $domain->domain] = HttpsPorts::of($domain);
        }
        ksort($domains);

        return [
            'allowed' => $this->grants->of((string) $user->username),
            'ports' => self::recorded($user)['ports'] ?? [],
            'main_domain' => $user->domain,
            'domains' => $domains,
        ];
    }

    /**
     * Every domain of the project back on 443 alone: the operator took the ports away.
     *
     * @return list<string> the domains that answered HTTPS elsewhere
     */
    private function closeHttpsPorts(User $user): array
    {
        $closed = [];
        foreach ($user->domains()->getQuery()->get() as $domain) {
            /** @var Domain $domain */
            if (HttpsPorts::hasExtra($domain)) {
                $this->httpsPorts->apply($domain, [HttpsPorts::DEFAULT], HttpsPorts::DEFAULT);
                $closed[] = (string) $domain->domain;
            }
        }

        return $closed;
    }

    /** Off the server's name, since the operator took the permission away. */
    private function releaseHostDomain(User $user): void
    {
        $domain = $this->hostDomain->release(
            $user,
            self::recorded($user)['domain'] ?? null,
            HostDomain::NOT_ALLOWED,
            'the operator has not allowed this project the server\'s own name'
        );
        $this->record($user, ['domain' => $domain]);
    }

    /**
     * The run file with each declared `to` published on the account container, where the rules forward to it.
     *
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    public static function publishDeclaredPorts(User $user, array $compose, ?DeployLogger $logger = null): array
    {
        $ports = self::declared($user)->ports;
        if ($ports === []) {
            return $compose;
        }
        $binding = DeclaredPortBinding::apply($compose, $ports);
        foreach ($binding['published'] as $published) {
            $logger?->info("Published {$published} for public_ports");
        }
        foreach ($binding['unbound'] as $unbound) {
            $logger?->warn("No service to publish {$unbound} on for public_ports: none offers or publishes a port");
        }

        return $binding['compose'];
    }

    /** @param array<string, mixed> $changes */
    private function record(User $user, array $changes): void
    {
        $user->setDetails([self::DETAIL => array_merge(self::recorded($user), $changes)]);
        $user->save();
    }

    /**
     * @param  DomainState  $domain
     * @param  ?array<string, mixed>  $before
     */
    private function logDomain(PublicRouting $routing, array $domain, ?array $before, ?DeployLogger $logger): void
    {
        $name = (string) $domain['name'];
        $reason = rtrim($domain['reason'] ?? '', '.');
        $wasOnHost = ($before['state'] ?? null) === HostDomain::ON_HOST;
        if ($domain['state'] === HostDomain::ON_HOST) {
            $logger?->info("Serving the project on the server's own name {$name}".($wasOnHost ? '' : ' (domain: host)'));
        } elseif ($domain['state'] === HostDomain::REFUSED || ($domain['state'] === HostDomain::NOT_ALLOWED && $routing->wantsHostDomain())) {
            $logger?->warn("domain: host was not applied: {$reason}; the project stays on {$name}");
        } elseif (($domain['applied'] ?? false) === true && $routing->wantsHostDomain()) {
            $logger?->info("domain: host was applied at an earlier deploy; the project is on {$name} now, and the file does not move it back");
        }
        if ($wasOnHost && $domain['state'] === HostDomain::NOT_ALLOWED) {
            $logger?->info("Moved off the server's own name to {$name}: {$reason}");
        }
    }

    /** @param PortState $state */
    private function logPort(array $state, ?DeployLogger $logger): void
    {
        $port = "Public port {$state['transport']} {$state['listen']} → {$state['to']}";
        $reason = $state['reason'] ?? '';
        match ($state['state']) {
            PublicPortRules::OPEN => $logger?->info("{$port} is open"),
            default => $logger?->warn("{$port} was not opened: {$reason}"),
        };
    }
}
