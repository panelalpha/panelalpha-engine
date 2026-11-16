<?php

namespace App\Integrations\Tunnels;

/**
 * Where a tunnel connector in an account reaches a domain's host proxy vhost.
 * Not host.docker.internal: nginx-proxy binds only the account's addresses once it has any.
 */
final class HostProxyOrigin
{
    public const string GATEWAY = 'host.docker.internal';

    /**
     * @param  ?string  $ruleListenIp  the listen_ip of the domain's rule on that port; `*` or empty means every account address
     * @param  array{ipv4: list<string>, ipv6: list<string>}  $accountIps
     */
    public static function url(bool $ssl, ?string $ruleListenIp, array $accountIps): string
    {
        $scheme = $ssl ? 'https' : 'http';
        $port = $ssl ? 443 : 80;

        return "{$scheme}://".self::urlHost(self::address($ruleListenIp, $accountIps)).":{$port}";
    }

    /**
     * @param  array{ipv4: list<string>, ipv6: list<string>}  $accountIps
     */
    private static function address(?string $ruleListenIp, array $accountIps): string
    {
        $ruleListenIp = trim((string) $ruleListenIp);
        if ($ruleListenIp !== '' && $ruleListenIp !== '*') {
            return $ruleListenIp;
        }

        return $accountIps['ipv4'][0] ?? $accountIps['ipv6'][0] ?? self::GATEWAY;
    }

    private static function urlHost(string $address): string
    {
        return str_contains($address, ':') ? "[{$address}]" : $address;
    }
}
