<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which `source_ip` the Engine may reach a source site at: a public address
 * only. Loopback, link-local, private and reserved ranges are refused (Docker
 * networks live in the private ones), and so is every address of the Engine itself.
 */
class SourceIpPolicy
{
    public function __construct(
        private EngineAddresses $engine = new EngineAddresses,
    ) {}

    /**
     * Why the address is refused, or null when it may be used.
     */
    public function refusal(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'source_ip must be an IPv4 or IPv6 address.';
        }

        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $public) === false) {
            return 'source_ip must be a public address: loopback, link-local, private, reserved '
                .'and Docker network addresses are refused.';
        }

        if (IpUtils::checkIp($ip, $this->engine->ranges())) {
            return "source_ip is one of this Engine's own addresses.";
        }

        return null;
    }
}
