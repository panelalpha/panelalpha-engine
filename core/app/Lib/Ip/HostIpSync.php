<?php

namespace App\Lib\Ip;

use App\Models\IpSubnet;
use App\System;
use App\System\Privileged;
use Exception;

/**
 * Puts the database's subnets back on the host, each routed through its family's default route with its addresses on
 * that route's interface. A family's default route is looked up only when a subnet of that family exists.
 */
class HostIpSync
{
    public function __construct(private readonly System $system) {}

    /**
     * What was put back and what could not be, in order; `ok` is false for what could not.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public function sync(bool $restartNetworking): array
    {
        $subnets = IpSubnet::with('ipAssigned')->get();
        [$routes, $lines] = $this->defaultRoutes(array_values(array_unique($subnets->pluck('family')->all())));

        if ($restartNetworking) {
            $this->system->runProcessOnHost(Privileged::network()->restartNetworking());
        }

        foreach ($subnets as $subnet) {
            /** @var IpSubnet $subnet */
            $route = $routes[$subnet->family] ?? null;
            if ($route === null) {
                $lines[] = self::failure("Skipping subnet {$subnet->ip}/{$subnet->mask} due to missing route.");

                continue;
            }
            array_push($lines, ...$this->syncSubnet($subnet, $route));
        }

        return $lines;
    }

    /**
     * Before networking restarts, as the host has them.
     *
     * @param  list<int>  $families
     * @return array{0: array<int, array{gateway: string, interface: string}>, 1: list<array{ok: bool, message: string}>}
     */
    private function defaultRoutes(array $families): array
    {
        $routes = [];
        $failures = [];
        foreach ($families as $family) {
            try {
                $routes[$family] = $this->system->network()->defaultRoute($family);
            } catch (Exception $e) {
                $failures[] = self::failure("Could not get default IPv{$family} route: ".$e->getMessage());
            }
        }

        return [$routes, $failures];
    }

    /**
     * @param  array{gateway: string, interface: string}  $route
     * @return list<array{ok: bool, message: string}>
     */
    private function syncSubnet(IpSubnet $subnet, array $route): array
    {
        $network = $this->system->network();
        $lines = [$this->run(
            fn (): array => $network->generateIpRouteAddCommand($subnet->ip, $subnet->mask, $subnet->family, $route['gateway'], $route['interface']),
            "Added IP route for {$subnet->ip}/{$subnet->mask}",
            "Could not add IP route {$subnet->ip}/{$subnet->mask}",
        )];
        foreach ($subnet->listAssignedIpAddresses() as $ip) {
            $lines[] = $this->run(
                fn (): array => $network->generateIpAddressAddCommand($ip, $subnet->mask, $subnet->family, $route['interface']),
                "Added IP address {$ip}",
                "Could not add IP address {$ip}/{$subnet->mask}",
            );
        }

        return $lines;
    }

    /**
     * @param  callable(): list<string>  $command
     * @return array{ok: bool, message: string}
     */
    private function run(callable $command, string $done, string $failed): array
    {
        try {
            $argv = $command();
            $this->system->runProcessOnHost($argv);
        } catch (Exception $e) {
            return self::failure("{$failed}: ".$e->getMessage());
        }

        return ['ok' => true, 'message' => "{$done}: ".implode(' ', $argv)];
    }

    /** @return array{ok: bool, message: string} */
    private static function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
