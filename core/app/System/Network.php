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
    ) {
    }

    /**
     * @return array{gateway: string, interface: string}
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
                'gateway'   => $m[1],
                'interface' => $m[2],
            ];
        }

        throw new Exception("Unable to parse default route for IPv$family: $output");
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    public function generateIpRouteAddCommand(string $ip, int $mask, int $family, ?string $gateway = null, ?string $interface = null): array
    {
        if ($gateway === null || $interface === null) {
            $route = $family === 4
                ? $this->getDefaultIpv4Route()
                : $this->getDefaultIpv6Route();
            if ($gateway === null) {
                $gateway = $route['gateway'];
            }
            if ($interface === null) {
                $interface = $route['interface'];
            }
        }
        return [
            'ip',
            'route',
            'add',
            $ip . '/' . $mask,
            'via',
            $gateway,
            'dev',
            $interface,
        ];
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    public function generateIpRouteDelCommand(string $ip, int $mask, int $family, ?string $gateway = null, ?string $interface = null): array
    {
        if ($gateway === null || $interface === null) {
            $route = $family === 4
                ? $this->getDefaultIpv4Route()
                : $this->getDefaultIpv6Route();
            if ($gateway === null) {
                $gateway = $route['gateway'];
            }
            if ($interface === null) {
                $interface = $route['interface'];
            }
        }

        return [
            'ip',
            'route',
            'del',
            $ip . '/' . $mask,
            'via',
            $gateway,
            'dev',
            $interface,
        ];
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    public function generateIpAddressAddCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        if ($interface === null) {
            $route = $family === 4
                ? $this->getDefaultIpv4Route()
                : $this->getDefaultIpv6Route();
            $interface = $route['interface'];
        }

        return [
            'ip',
            'addr',
            'add',
            $ip . '/' . $mask,
            'dev',
            $interface,
        ];
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    public function generateIpAddressDelCommand(string $ip, int $mask, int $family, ?string $interface = null): array
    {
        if ($interface === null) {
            $route = $family === 4
                ? $this->getDefaultIpv4Route()
                : $this->getDefaultIpv6Route();
            $interface = $route['interface'];
        }

        return [
            'ip',
            'addr',
            'del',
            $ip . '/' . $mask,
            'dev',
            $interface,
        ];
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
        $maps = [];

        foreach ($localIps as $localIp) {
            $publicIp = $this->lookupPublicIp($localIp, $lookupUrl);
            if ($publicIp === null || $publicIp === $localIp) {
                continue;
            }

            $maps[] = Ipv4NatMap::upsertMap($localIp, $publicIp);
        }

        $defaultIpv4Previous = Setting::get('default_ipv4');
        $defaultIpv4Current = $defaultIpv4Previous;
        $defaultIpv4Replaced = false;

        if ($replaceDefaultIpv4 && !empty($defaultIpv4Previous)) {
            $publicToLocal = Ipv4NatMap::getPublicToLocalMap();
            if (isset($publicToLocal[$defaultIpv4Previous])) {
                $localToPublic = Ipv4NatMap::getLocalToPublicMap();
                $publicIp = $localToPublic[$defaultIpv4Previous] ?? null;
                if ($publicIp !== null) {
                    Setting::set('default_ipv4', $publicIp);
                    $defaultIpv4Current = $publicIp;
                    $defaultIpv4Replaced = true;
                }
            } elseif (in_array($defaultIpv4Previous, $localIps, true)) {
                $localToPublic = Ipv4NatMap::getLocalToPublicMap();
                $publicIp = $localToPublic[$defaultIpv4Previous] ?? null;
                if ($publicIp !== null) {
                    Setting::set('default_ipv4', $publicIp);
                    $defaultIpv4Current = $publicIp;
                    $defaultIpv4Replaced = true;
                }
            }
        }

        return [
            'maps' => $maps,
            'default_ipv4_replaced' => $defaultIpv4Replaced,
            'default_ipv4_previous' => $defaultIpv4Previous,
            'default_ipv4_current' => $defaultIpv4Current,
        ];
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
            if (!preg_match('/\binet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\//', $line, $matches)) {
                continue;
            }

            $ip = $matches[1];
            if ($this->isUsableHostIpv4($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function lookupPublicIp(string $localIp, string $lookupUrl): ?string
    {
        try {
            $url = $lookupUrl;
            if (strpos($url, '?') === false) {
                $url .= '?local_ip=' . urlencode($localIp);
            }

            $response = Http::timeout(10)->get($url);
            if (!$response->ok()) {
                return null;
            }

            $body = trim($response->body());
            if (!filter_var($body, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return null;
            }

            return $body;
        } catch (\Exception $e) {
            Log::warning('Could not lookup public IP for local IP', [
                'local_ip' => $localIp,
                'lookup_url' => $lookupUrl,
                'exception_message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isUsableHostIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (strpos($ip, '127.') === 0) {
            return false;
        }

        if (strpos($ip, '0.') === 0) {
            return false;
        }

        return true;
    }
}
