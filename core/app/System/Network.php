<?php

namespace App\System;

use App\Exceptions\ProblemException;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Host network inspection and IP command builders.
 */
class Network
{
    public const DEFAULT_IPV4_NAT_LOOKUP_URL = 'https://icanhazip.com';

    /** Kinds of interface that sit on a NIC and carry its traffic; a physical NIC has no kind at all. */
    private const UPLINK_KINDS = ['bond', 'team', 'vlan'];

    private const LOOKUP_TIMEOUT_SECONDS = 10;

    /** @var array{gateway: string, interface: string}|null */
    private static ?array $defaultIpv4Route = null;

    /** @var array{gateway: string, interface: string}|null */
    private static ?array $defaultIpv6Route = null;

    public function __construct(
        private ProcessRunner $system,
    ) {}

    /**
     * @return array{gateway: string, interface: string}
     *
     * @throws Exception
     */
    public function getDefaultIpv4Route(): array
    {
        if (self::$defaultIpv4Route === null) {
            self::$defaultIpv4Route = $this->getDefaultGatewayAndInterface(4);
        }

        return self::$defaultIpv4Route;
    }

    /**
     * @return array{gateway: string, interface: string}
     *
     * @throws Exception
     */
    public function getDefaultIpv6Route(): array
    {
        if (self::$defaultIpv6Route === null) {
            self::$defaultIpv6Route = $this->getDefaultGatewayAndInterface(6);
        }

        return self::$defaultIpv6Route;
    }

    /**
     * @return array{gateway: string, interface: string}
     *
     * @throws Exception
     */
    private function getDefaultGatewayAndInterface(int $family = 4): array
    {
        $process = $this->system->runProcessOnHost(Privileged::network()->defaultRoute($family));
        $output = $process->getOutput();
        if (empty($output)) {
            throw new Exception("No default route found for IPv$family");
        }

        if (preg_match('/default via (\S+) dev (\S+)/', $output, $m)) {
            return [
                'gateway' => $m[1],
                'interface' => $m[2],
            ];
        }

        throw new Exception("Unable to parse default route for IPv$family: $output");
    }

    /**
     * The host's default route for IPv4 (4) or IPv6 (6).
     *
     * @return array{gateway: string, interface: string}
     *
     * @throws Exception
     */
    public function defaultRoute(int $family): array
    {
        return $family === 4 ? $this->getDefaultIpv4Route() : $this->getDefaultIpv6Route();
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpRouteAddCommand(string $ip, int $mask, int $family, ?string $gateway = null, ?string $interface = null): array
    {
        return $this->ipRouteCommand('add', $ip, $mask, $family, $gateway, $interface);
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpRouteDelCommand(string $ip, int $mask, int $family, ?string $gateway = null, ?string $interface = null): array
    {
        return $this->ipRouteCommand('del', $ip, $mask, $family, $gateway, $interface);
    }

    /**
     * `ip route <verb>` via the given gateway and interface, each defaulting to the default route's.
     *
     * @return list<string>
     *
     * @throws Exception
     */
    private function ipRouteCommand(string $verb, string $ip, int $mask, int $family, ?string $gateway, ?string $interface): array
    {
        if ($gateway === null || $interface === null) {
            $route = $this->defaultRoute($family);
            $gateway ??= $route['gateway'];
            $interface ??= $route['interface'];
        }

        return $verb === 'add'
            ? Privileged::network()->addRoute($ip, $mask, $gateway, $interface)
            : Privileged::network()->deleteRoute($ip, $mask, $gateway, $interface);
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpAddressAddCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        return Privileged::network()->addAddress($ip, $mask, $interface ?? $this->defaultRoute($family)['interface']);
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpAddressDelCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        return Privileged::network()->deleteAddress($ip, $mask, $interface ?? $this->defaultRoute($family)['interface']);
    }

    /**
     * Asks the lookup service, from each uplink address, which public address it arrives as. A stored map onto an
     * address the host holds itself, or from one on a Docker interface, is dropped.
     *
     * @return array{
     *   maps: array<Ipv4NatMap>,
     *   default_ipv4_replaced: bool,
     *   default_ipv4_previous: ?string,
     *   default_ipv4_current: ?string,
     * }
     *
     * @throws ProblemException when two local addresses would map to one public address
     */
    public function rebuildIpv4NatMaps(
        string $lookupUrl = self::DEFAULT_IPV4_NAT_LOOKUP_URL,
        bool $replaceDefaultIpv4 = false,
    ): array {
        $interfaces = $this->hostIpv4Interfaces();
        $localIps = $this->uplinkIpv4Addresses($interfaces);
        $previousPublicToLocal = Ipv4NatMap::getPublicToLocalMap();
        $maps = $this->upsertDiscoveredMaps($localIps, $this->ipv4AddressesOf($interfaces), $this->dockerIpv4Addresses($interfaces, $localIps), $lookupUrl);

        $defaultIpv4Previous = Setting::get('default_ipv4');
        $replacement = $replaceDefaultIpv4 && ! empty($defaultIpv4Previous)
            ? $this->replaceDefaultIpv4($defaultIpv4Previous, $localIps, $previousPublicToLocal)
            : null;
        $defaultIpv4Current = $replacement ?? $defaultIpv4Previous;
        $defaultIpv4Replaced = $replacement !== null;

        return [
            'maps' => $maps,
            'default_ipv4_replaced' => $defaultIpv4Replaced,
            'default_ipv4_previous' => $defaultIpv4Previous,
            'default_ipv4_current' => $defaultIpv4Current,
        ];
    }

    /**
     * Forgets the stored maps from addresses the host holds on Docker's own interfaces, without asking the lookup
     * service. A map from any other address, the host's or not, stays.
     *
     * @return list<Ipv4NatMap> the maps forgotten
     *
     * @throws Exception when the host's interfaces cannot be read
     */
    public function forgetDockerIpv4NatMaps(): array
    {
        $process = $this->system->runProcessOnHost(Privileged::network()->addressDetails());
        if (! $process->isSuccessful()) {
            throw new Exception("Could not read the host's interfaces: ".(trim($process->getErrorOutput()) ?: trim($process->getOutput())));
        }
        $interfaces = self::interfacesIn($process->getOutput());

        return $this->forgetMapsFrom($this->dockerIpv4Addresses($interfaces, $this->uplinkIpv4Addresses($interfaces)));
    }

    /**
     * @param  list<string>  $localIps
     * @param  list<string>  $hostIps
     * @param  list<string>  $dockerIps
     * @return array<Ipv4NatMap>
     *
     * @throws ProblemException
     */
    private function upsertDiscoveredMaps(array $localIps, array $hostIps, array $dockerIps, string $lookupUrl): array
    {
        $answers = $this->discoverPublicIps($localIps, $lookupUrl);
        $translated = array_filter(
            $answers,
            static fn (string $publicIp, string|int $localIp): bool => $publicIp !== (string) $localIp,
            ARRAY_FILTER_USE_BOTH,
        );
        $this->refuseSharedPublicIps(array_diff_key($this->storedMapsNotOnto($hostIps), $translated, array_flip($dockerIps)), $answers);
        $this->forgetMapsOnto($hostIps);
        $this->forgetMapsFrom($dockerIps);

        $maps = [];
        foreach ($translated as $localIp => $publicIp) {
            $maps[] = Ipv4NatMap::upsertMap((string) $localIp, $publicIp);
        }

        return $maps;
    }

    /**
     * Each lookup's answer, by the local address it left from. An address that answers as itself is behind no NAT.
     *
     * @param  list<string>  $localIps
     * @return array<string, string>
     */
    private function discoverPublicIps(array $localIps, string $lookupUrl): array
    {
        $answers = [];
        foreach ($localIps as $localIp) {
            $publicIp = $this->lookupPublicIp($localIp, $lookupUrl);
            if ($publicIp !== null) {
                $answers[$localIp] = $publicIp;
            }
        }

        return $answers;
    }

    /**
     * @param  list<string>  $hostIps
     * @return array<string, string>
     */
    private function storedMapsNotOnto(array $hostIps): array
    {
        return array_filter(
            Ipv4NatMap::getLocalToPublicMap(),
            static fn (string $publicIp): bool => ! in_array($publicIp, $hostIps, true),
        );
    }

    /**
     * An address the host holds is no NAT's public side, so a map onto one was stored by mistake, and binding the
     * sites through it takes that address off the air.
     *
     * @param  list<string>  $hostIps
     */
    private function forgetMapsOnto(array $hostIps): void
    {
        foreach (Ipv4NatMap::query()->whereIn('public_ip', $hostIps)->get() as $map) {
            $map->delete();
        }
    }

    /**
     * No NAT translates an address on docker0, a Docker bridge or a veth, so a map from one was stored by the
     * discovery from before uplinks were told apart, and binding the sites through it puts them on that bridge.
     *
     * @param  list<string>  $dockerIps
     * @return list<Ipv4NatMap>
     */
    private function forgetMapsFrom(array $dockerIps): array
    {
        $maps = array_values(Ipv4NatMap::query()->whereIn('local_ip', $dockerIps)->get()->all());
        foreach ($maps as $map) {
            $map->delete();
        }

        return $maps;
    }

    /**
     * A 1:1 NAT gives each public address to one local address. Two claiming one, as a stored map or as a lookup's
     * answer (an address answering as itself claims itself), would bind every site on it to just one of them.
     *
     * @param  array<string, string>  $stored  the stored maps that stay and that no answer replaces
     * @param  array<string, string>  $answers
     *
     * @throws ProblemException
     */
    private function refuseSharedPublicIps(array $stored, array $answers): void
    {
        foreach (self::claimsByPublicIp($stored, $answers) as $publicIp => $claims) {
            if ($claims['answered'] !== [] && count($claims['stored']) + count($claims['answered']) > 1) {
                throw ProblemException::conflict('ipv4_nat_maps', 'nat_public_ip_shared', self::sharedPublicIpMessage((string) $publicIp, $claims));
            }
        }
    }

    /**
     * @param  array<string, string>  $stored
     * @param  array<string, string>  $answers
     * @return array<string, array{stored: list<string>, answered: list<string>}>
     */
    private static function claimsByPublicIp(array $stored, array $answers): array
    {
        $claims = [];
        foreach (['stored' => $stored, 'answered' => $answers] as $source => $localToPublic) {
            foreach ($localToPublic as $localIp => $publicIp) {
                $claims[$publicIp] ??= ['stored' => [], 'answered' => []];
                $claims[$publicIp][$source][] = (string) $localIp;
            }
        }

        return $claims;
    }

    /** @param  array{stored: list<string>, answered: non-empty-list<string>}  $claims */
    private static function sharedPublicIpMessage(string $publicIp, array $claims): string
    {
        $unchanged = ' No NAT map was changed and no site was rebound.';
        if (count($claims['answered']) === 1) {
            return "Refusing to store the discovered IPv4 NAT maps: {$claims['answered'][0]} reaches the internet as {$publicIp}, "
                ."which the stored NAT map {$claims['stored'][0]} => {$publicIp} already gives to another local address. "
                .'Delete that map if it is stale, then run the discovery again.'.$unchanged;
        }
        $localIps = $claims['answered'];
        $last = array_pop($localIps);

        return 'Refusing to store the discovered IPv4 NAT maps: '.implode(', ', $localIps)." and {$last} reach the internet as the same public address {$publicIp}. "
            .'A 1:1 NAT gives each public address to one local address, so this host shares one public address between several, '
            .'or the lookup service does not answer with the address a request came from.'.$unchanged;
    }

    /**
     * Point default_ipv4 at the public address NAT maps its local address to, when it names a
     * local address or one the previous map gave out; null when nothing was replaced.
     *
     * @param  list<string>  $localIps
     * @param  array<string, string>  $previousPublicToLocal
     */
    private function replaceDefaultIpv4(string $previous, array $localIps, array $previousPublicToLocal): ?string
    {
        $localIp = in_array($previous, $localIps, true) ? $previous : ($previousPublicToLocal[$previous] ?? null);
        $publicIp = $localIp === null ? null : (Ipv4NatMap::getLocalToPublicMap()[$localIp] ?? null);
        if ($publicIp === null || $publicIp === $previous) {
            return null;
        }

        Setting::set('default_ipv4', $publicIp);

        return $publicIp;
    }

    /**
     * @return list<string>
     */
    public function localIpv4Addresses(): array
    {
        $process = $this->system->runProcessOnHost(Privileged::network()->addresses());
        $output = $process->getOutput();
        if (empty($output)) {
            return [];
        }

        $ips = [];
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (! preg_match('/\binet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\//', $line, $matches)) {
                continue;
            }

            $ip = $matches[1];
            if ($this->isUsableHostIpv4($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /** @return list<array<string, mixed>> the host's interfaces with their IPv4 addresses, as `ip -json` prints them */
    private function hostIpv4Interfaces(): array
    {
        return self::interfacesIn($this->system->runProcessOnHost(Privileged::network()->addressDetails())->getOutput());
    }

    /** @return list<array<string, mixed>> */
    private static function interfacesIn(string $json): array
    {
        $interfaces = json_decode($json, true);

        return is_array($interfaces) ? array_values(array_filter($interfaces, is_array(...))) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function ipv4AddressesOf(array $interfaces): array
    {
        $ips = [];
        foreach ($interfaces as $interface) {
            foreach ($interface['addr_info'] ?? [] as $address) {
                $ip = (string) ($address['local'] ?? '');
                if (($address['family'] ?? null) === 'inet' && $this->isUsableHostIpv4($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Addresses on the host's uplinks, the only ones a 1:1 NAT translates.
     *
     * @param  list<array<string, mixed>>  $interfaces
     * @return list<string>
     */
    private function uplinkIpv4Addresses(array $interfaces): array
    {
        $defaultInterface = $this->defaultIpv4Interface();

        return $this->ipv4AddressesOf(array_values(array_filter(
            $interfaces,
            static fn (array $interface): bool => self::isUplink($interface, $defaultInterface),
        )));
    }

    /**
     * Addresses on Docker's own interfaces; an address an uplink holds as well is not one.
     *
     * @param  list<array<string, mixed>>  $interfaces
     * @param  list<string>  $uplinkIps
     * @return list<string>
     */
    private function dockerIpv4Addresses(array $interfaces, array $uplinkIps): array
    {
        $dockerInterfaces = array_values(array_filter(
            $interfaces,
            static fn (array $interface): bool => self::isDockerInterface((string) ($interface['ifname'] ?? '')),
        ));

        return array_values(array_diff($this->ipv4AddressesOf($dockerInterfaces), $uplinkIps));
    }

    /** Read afresh rather than from the route cache; null when the host has no default route with a gateway. */
    private function defaultIpv4Interface(): ?string
    {
        try {
            return $this->getDefaultGatewayAndInterface(4)['interface'];
        } catch (Exception) {
            return null;
        }
    }

    /**
     * A NIC, a bond, team or VLAN on one, or the bridge the default route leaves through. Not loopback, a Docker
     * bridge, a veth, a tunnel or a dummy: a lookup from one leaves through the default route and says nothing.
     *
     * @param  array<string, mixed>  $interface
     */
    private static function isUplink(array $interface, ?string $defaultInterface): bool
    {
        if (($interface['link_type'] ?? null) !== 'ether') {
            return false;
        }
        $kind = $interface['linkinfo']['info_kind'] ?? null;
        if ($kind === 'bridge') {
            return $defaultInterface !== null
                && ($interface['ifname'] ?? null) === $defaultInterface
                && ! self::isDockerBridge($defaultInterface);
        }

        return $kind === null || in_array($kind, self::UPLINK_KINDS, true);
    }

    /** docker0, and the bridges Docker names br-<id>, the tenant and build networks' included. */
    private static function isDockerBridge(string $name): bool
    {
        return $name === 'docker0' || str_starts_with($name, 'br-');
    }

    /** A Docker bridge, or a veth Docker plugs a container into one with. */
    private static function isDockerInterface(string $name): bool
    {
        return self::isDockerBridge($name) || str_starts_with($name, 'veth');
    }

    /**
     * Whether the interface carries `$ip/$mask` now: `ip addr add` exiting 0
     * does not prove it. The default route's interface when none is given.
     *
     * @throws Exception when the interface cannot be inspected
     */
    public function interfaceHasAddress(string $ip, int $mask, int $family, ?string $interface = null): bool
    {
        $interface ??= $this->defaultRoute($family)['interface'];

        $process = $this->system->runProcessOnHost(Privileged::network()->interfaceAddresses($interface));
        if (! $process->isSuccessful()) {
            throw new Exception(
                "Could not read addresses of interface {$interface}: "
                .($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (! preg_match('/\binet6?\s+([0-9a-fA-F:.]+)\/(\d+)/', $line, $matches)) {
                continue;
            }

            if (self::sameAddress($matches[1], $ip) && (int) $matches[2] === $mask) {
                return true;
            }
        }

        return false;
    }

    /** The kernel prints the canonical form, so `2001:db8:0:0::5` and `2001:db8::5` are one address. */
    public static function sameAddress(string $a, string $b): bool
    {
        if (filter_var($a, FILTER_VALIDATE_IP) === false || filter_var($b, FILTER_VALIDATE_IP) === false) {
            return strtolower($a) === strtolower($b);
        }

        return inet_pton($a) === inet_pton($b);
    }

    /**
     * Whether the host routes exactly `$ip/$mask` now: `ip route add` can fail, or exit 0, without proving it.
     *
     * @throws Exception when the routes cannot be read
     */
    public function hasRoute(string $ip, int $mask, int $family): bool
    {
        $process = $this->system->runProcessOnHost(Privileged::network()->route($ip, $mask, $family));
        if (! $process->isSuccessful()) {
            throw new Exception("Could not read the host's routes: ".($process->getErrorOutput() ?: $process->getOutput()));
        }

        return trim($process->getOutput()) !== '';
    }

    private function lookupPublicIp(string $localIp, string $lookupUrl): ?string
    {
        try {
            return $this->fetchPublicIp($localIp, $lookupUrl);
        } catch (Exception $e) {
            Log::warning('Could not lookup public IP for local IP', [
                'local_ip' => $localIp,
                'lookup_url' => $lookupUrl,
                'exception_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @throws Exception when the lookup fails */
    private function fetchPublicIp(string $localIp, string $lookupUrl): ?string
    {
        $process = $this->system->runProcessOnHost($this->lookupArgv($localIp, $lookupUrl), [], self::LOOKUP_TIMEOUT_SECONDS + 5);
        if (! $process->isSuccessful()) {
            throw new Exception(trim($process->getErrorOutput()) ?: "The lookup exited with code {$process->getExitCode()}.");
        }

        $body = trim($process->getOutput());

        return $this->isUsableHostIpv4($body) ? $body : null;
    }

    /**
     * curl in the host's namespaces, bound to $localIp, so the service sees what the NAT turns that address into.
     * A URL without a query is told which address it is about.
     *
     * @return list<string>
     */
    private function lookupArgv(string $localIp, string $lookupUrl): array
    {
        $url = str_contains($lookupUrl, '?') ? $lookupUrl : $lookupUrl.'?local_ip='.urlencode($localIp);

        return Privileged::network()->publicAddressLookup($localIp, $url, self::LOOKUP_TIMEOUT_SECONDS);
    }

    /** A valid IPv4 address outside 127.0.0.0/8 and 0.0.0.0/8. */
    private function isUsableHostIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ! str_starts_with($ip, '127.')
            && ! str_starts_with($ip, '0.');
    }
}
