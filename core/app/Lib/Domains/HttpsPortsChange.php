<?php

namespace App\Lib\Domains;

use App\Exceptions\ProblemException;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Services\Webserver\ProxyListenPort;

/**
 * Moving a container domain's HTTPS to other ports: a port other than 443 needs the operator's `ports` permission and
 * must be free of everything but other domains' HTTPS, which shares nginx's listener by name.
 */
class HttpsPortsChange
{
    public const MAX_PORTS = 8;

    /** The code a port refused for want of the operator's permission answers with. */
    public const NOT_ALLOWED = 'https_port_not_allowed';

    public function __construct(
        private readonly System $system,
        private readonly PublicRoutingGrants $grants,
    ) {}

    /**
     * @param  list<int>  $ports
     *
     * @throws ProblemException
     */
    public function assertAllowed(Domain $domain, array $ports, int $main): void
    {
        $refusal = $this->refusal($domain, $ports, $main);
        if ($refusal !== null) {
            throw ProblemException::one($refusal['field'], $refusal['code'], $refusal['message']);
        }
    }

    /**
     * Why $domain may not answer HTTPS on $ports with $main the one its URL names, or null when it may.
     *
     * @param  list<int>  $ports
     * @return ?array{field: string, code: string, message: string}
     */
    public function refusal(Domain $domain, array $ports, int $main): ?array
    {
        $user = $domain->getUser();
        if ($user->getTemplate() !== 'dind') {
            return self::problem(HttpsPorts::PORTS_KEY, 'https_ports_container_only', 'Only the domains of a container project can answer HTTPS on other ports.');
        }
        if (! $domain->sslEnabled()) {
            return self::problem(HttpsPorts::PORTS_KEY, 'https_ports_need_ssl', "{$domain->domain} was added without SSL, so it has no HTTPS to move.");
        }
        if ($ports === [] || count($ports) > self::MAX_PORTS) {
            return self::problem(HttpsPorts::PORTS_KEY, 'https_ports_count', 'A domain answers HTTPS on 1 to '.self::MAX_PORTS.' ports.');
        }
        foreach ([...$ports, $main] as $port) {
            if ($port < 1 || $port > 65535) {
                return self::problem(HttpsPorts::PORTS_KEY, 'https_ports_invalid', "{$port} is not a port number (1-65535).");
            }
        }
        if (! in_array(HttpsPorts::DEFAULT, $ports, true) && (DomainPlan::inOnlineZone((string) $domain->domain) || $domain->hasTunnels())) {
            return self::problem(HttpsPorts::PORTS_KEY, 'https_ports_tunnelled', "{$domain->domain} is reached through a tunnel, which comes in on 443: keep 443 among its ports.");
        }
        if (! in_array($main, $ports, true)) {
            return self::problem(HttpsPorts::MAIN_KEY, 'https_port_not_listed', "Port {$main} is not one of the domain's HTTPS ports: add it to https_ports first.");
        }
        foreach (array_diff($ports, HttpsPorts::of($domain)) as $port) {
            $refusal = $this->portRefusal($user, $port);
            if ($refusal !== null) {
                return self::problem(HttpsPorts::PORTS_KEY, $refusal[0], $refusal[1]);
            }
        }

        return null;
    }

    /**
     * Writes the ports, renders the domain on them, and opens or closes the firewall and the default servers the
     * ports other than 443 need.
     *
     * @param  list<int>  $ports
     */
    public function apply(Domain $domain, array $ports, int $main): void
    {
        $listenersBefore = HttpsPorts::of($domain);
        $details = array_diff_key($domain->getDetails(), [HttpsPorts::PORTS_KEY => true, HttpsPorts::MAIN_KEY => true]);
        if ($ports !== [HttpsPorts::DEFAULT]) {
            $details[HttpsPorts::PORTS_KEY] = $ports;
            $details[HttpsPorts::MAIN_KEY] = $main;
        }
        $domain->details = $details;
        $domain->save();

        $domain->projectDomain()->rebuild();
        if (self::sorted($listenersBefore) !== self::sorted($ports)) {
            $this->listenersChanged();
        } else {
            $this->system->reloadWebserver();
        }
    }

    /** After a port other than 443 was taken or freed: the default servers and firewall allows follow, then nginx. */
    public function listenersChanged(): void
    {
        $this->system->webserver()->applyHttpsPorts();
        $this->system->reloadWebserver();
    }

    /** @return ?array{0: string, 1: string} */
    private function portRefusal(User $user, int $port): ?array
    {
        if ($port === HttpsPorts::DEFAULT) {
            return null;
        }
        if ($port === 80) {
            return ['https_port_webserver', 'Port 80 is the webserver\'s plain-HTTP port.'];
        }
        if (in_array($port, ProxyListenPort::RESERVED, true)) {
            return ['https_port_reserved', "Port {$port} is reserved by the engine."];
        }
        if (! $this->grants->allows($user->username, PublicRoutingGrants::PORTS)) {
            return [self::NOT_ALLOWED, 'The operator has not allowed this project HTTPS ports other than 443.'];
        }
        $rule = ProxyRule::query()->where('enabled', true)->where('listen_port', $port)->whereIn('transport', ['http', 'tcp'])->first();
        if ($rule !== null) {
            return ['https_port_taken', "Port {$port} is used by proxy rule {$rule->id} ({$rule->transport})."];
        }
        if (HttpsPorts::namesOn($port) === [] && (new ProxyListenPort($this->system))->boundOnHost('tcp', '*', $port)) {
            return ['https_port_taken', "Port {$port} is already in use on this host."];
        }

        return null;
    }

    /** @return array{field: string, code: string, message: string} */
    private static function problem(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @param  list<int>  $ports
     * @return list<int>
     */
    private static function sorted(array $ports): array
    {
        sort($ports);

        return $ports;
    }
}
