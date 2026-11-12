<?php

namespace App\System\Privileged;

/** The host's addresses, routes and sockets, in the host's namespaces. */
final class RootNetwork
{
    private const AREA = 'network';

    /** @return list<string> */
    public function defaultRoute(int $family): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::family($family)]);
    }

    /** @return list<string> */
    public function addresses(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function addressDetails(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function interfaceAddresses(string $interface): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::interface($interface)]);
    }

    /** @return list<string> */
    public function route(string $address, int $mask, int $family): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::family($family), HostValues::network($address, $mask)]);
    }

    /** @return list<string> */
    public function addRoute(string $address, int $mask, string $gateway, string $interface): array
    {
        return $this->changeRoute(__FUNCTION__, $address, $mask, $gateway, $interface);
    }

    /** @return list<string> */
    public function deleteRoute(string $address, int $mask, string $gateway, string $interface): array
    {
        return $this->changeRoute(__FUNCTION__, $address, $mask, $gateway, $interface);
    }

    /** @return list<string> */
    public function addAddress(string $address, int $mask, string $interface): array
    {
        return $this->changeAddress(__FUNCTION__, $address, $mask, $interface);
    }

    /** @return list<string> */
    public function deleteAddress(string $address, int $mask, string $interface): array
    {
        return $this->changeAddress(__FUNCTION__, $address, $mask, $interface);
    }

    /** @return list<string> */
    public function restartNetworking(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * Asks $url from $localAddress to learn its address after NAT.
     *
     * @return list<string>
     */
    public function publicAddressLookup(string $localAddress, string $url, int $timeout): array
    {
        $timeout = HostValues::positive($timeout, 'timeout');

        return $this->onHost(__FUNCTION__, [HostValues::ipv4($localAddress), HostValues::url($url), $timeout]);
    }

    /** @return list<string> */
    public function listeningSockets(string $protocol): array
    {
        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw InvalidArgument::of('protocol', $protocol);
        }

        return $this->onHost(__FUNCTION__, [$protocol]);
    }

    /** @return list<string> */
    private function changeRoute(string $action, string $address, int $mask, string $gateway, string $interface): array
    {
        return $this->onHost($action, [HostValues::network($address, $mask), HostValues::address($gateway), HostValues::interface($interface)]);
    }

    /** @return list<string> */
    private function changeAddress(string $action, string $address, int $mask, string $interface): array
    {
        return $this->onHost($action, [HostValues::network($address, $mask), HostValues::interface($interface)]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function onHost(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values, true);
    }
}
