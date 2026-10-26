<?php

namespace App\System;

use App\Models\Ipv4NatMap;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Host network inspection and IP command builders.
 */
class Network
{
    public const DEFAULT_IPV4_NAT_LOOKUP_URL = 'https://icanhazip.com';

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
        $cmd = $family === 6 ? ['ip', '-6', 'route', 'show', 'default'] : ['ip', 'route', 'show', 'default'];
        $process = $this->system->runProcessOnHost($cmd);
        $output = $process->getOutput();
        if (empty($output)) {
            throw new Exception("No default route found for IPv$family");
        }

        if (preg_match('/default via ([^ ]+) dev ([^ ]+)/', $output, $m)) {
            return [
                'gateway' => $m[1],
                'interface' => $m[2],
            ];
        }

        throw new Exception("Unable to parse default route for IPv$family: $output");
    }

    /**
     * @return array{gateway: string, interface: string}
     *
     * @throws Exception
     */
    private function defaultRoute(int $family): array
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

        return ['ip', 'route', $verb, $ip.'/'.$mask, 'via', $gateway, 'dev', $interface];
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpAddressAddCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        return ['ip', 'addr', 'add', $ip.'/'.$mask, 'dev', $interface ?? $this->defaultRoute($family)['interface']];
    }

    /**
     * @return list<string>
     *
     * @throws Exception
     */
    public function generateIpAddressDelCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        return ['ip', 'addr', 'del', $ip.'/'.$mask, 'dev', $interface ?? $this->defaultRoute($family)['interface']];
    }

    /**
     * @return array{
     *   maps: array<Ipv4NatMap>,
     *   default_ipv4_replaced: bool,
     *   default_ipv4_previous: ?string,
     *   default_ipv4_current: ?string,
     * }
     */
    public function rebuildIpv4NatMaps(
        string $lookupUrl = self::DEFAULT_IPV4_NAT_LOOKUP_URL,
        bool $replaceDefaultIpv4 = false,
    ): array {
        $localIps = $this->localIpv4Addresses();
        $previousPublicToLocal = Ipv4NatMap::getPublicToLocalMap();
        $maps = $this->upsertDiscoveredMaps($localIps, $lookupUrl);

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
     * @param  list<string>  $localIps
     * @return array<Ipv4NatMap>
     */
    private function upsertDiscoveredMaps(array $localIps, string $lookupUrl): array
    {
        $maps = [];
        foreach ($localIps as $localIp) {
            $publicIp = $this->lookupPublicIp($localIp, $lookupUrl);
            if ($publicIp !== null && $publicIp !== $localIp) {
                $maps[] = Ipv4NatMap::upsertMap($localIp, $publicIp);
            }
        }

        return $maps;
    }

    /**
     * Point the default IPv4 setting at the public address NAT now maps its local
     * address to, when it names a local address or one the map gave out before
     * this rebuild; null when nothing was replaced.
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
        $process = $this->system->runProcessOnHost(['ip', '-4', 'addr', 'show']);
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

    /**
     * Whether the interface carries `$ip/$mask` now: `ip addr add` exiting 0
     * does not prove it. The default route's interface when none is given.
     *
     * @throws Exception when the interface cannot be inspected
     */
    public function interfaceHasAddress(string $ip, int $mask, int $family, ?string $interface = null): bool
    {
        $interface ??= $this->defaultRoute($family)['interface'];

        $process = $this->system->runProcessOnHost(['ip', 'addr', 'show', 'dev', $interface]);
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
        $cmd = $family === 6 ? ['ip', '-6', 'route', 'show', "{$ip}/{$mask}"] : ['ip', 'route', 'show', "{$ip}/{$mask}"];
        $process = $this->system->runProcessOnHost($cmd);
        if (! $process->isSuccessful()) {
            throw new Exception("Could not read the host's routes: ".($process->getErrorOutput() ?: $process->getOutput()));
        }

        return trim($process->getOutput()) !== '';
    }

    private function lookupPublicIp(string $localIp, string $lookupUrl): ?string
    {
        try {
            $url = $lookupUrl;
            if (strpos($url, '?') === false) {
                $url .= '?local_ip='.urlencode($localIp);
            }

            $response = Http::timeout(10)->get($url);
            if (! $response->ok()) {
                return null;
            }

            $body = trim($response->body());
            if (! filter_var($body, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return null;
            }

            return $body;
        } catch (Exception $e) {
            Log::warning('Could not lookup public IP for local IP', [
                'local_ip' => $localIp,
                'lookup_url' => $lookupUrl,
                'exception_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A valid IPv4 address outside 127.0.0.0/8 and 0.0.0.0/8. */
    private function isUsableHostIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ! str_starts_with($ip, '127.')
            && ! str_starts_with($ip, '0.');
    }
}
