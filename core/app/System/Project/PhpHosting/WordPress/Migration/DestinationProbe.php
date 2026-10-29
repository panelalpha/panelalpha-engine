<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP requests the engine makes to an imported site by its name, always
 * answered by this engine whatever the name's DNS says.
 *
 * The name is pinned (CURLOPT_RESOLVE) to the IPv4 the project's vhost listens
 * on, the address the webserver binds: the site webserver runs on the host
 * network and binds the host's own addresses, so a public address behind
 * NAT is translated back to its local one, and loopback inside the core
 * container is core itself, never the webserver. A name with no project
 * falls back to the engine's default IPv4.
 */
final class DestinationProbe
{
    private const TIMEOUT_SECONDS = 30;

    /** HTTP status of the URL as this engine serves it; redirects are not followed. */
    public function status(string $url): int
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withOptions($this->options($url))
            ->get($url)
            ->status();
    }

    /** Whether this engine answers the URL at all. */
    public function answers(string $url): bool
    {
        try {
            return $this->status($url) > 0;
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function options(string $url): array
    {
        $options = ['allow_redirects' => false, 'verify' => false];
        $host = parse_url($url, PHP_URL_HOST);
        $address = is_string($host) ? self::engineAddress($host) : null;
        if (! is_string($host) || $address === null) {
            return $options;
        }

        $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:80:{$address}", "{$host}:443:{$address}"]];

        return $options;
    }

    /** The IPv4 the webserver answers the name on. */
    public static function engineAddress(string $host): ?string
    {
        $user = Domain::findByNameOrAlias(strtolower($host))?->user;
        $bound = $user instanceof User ? ($user->getBindIpAddresses()['ipv4'][0] ?? null) : null;
        $address = is_string($bound) && $bound !== '' ? $bound : trim((string) Setting::get('default_ipv4'));

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $address;
    }
}
