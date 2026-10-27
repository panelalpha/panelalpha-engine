<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Http\OutboundUrl;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which addresses the Engine may reach a source site at: `source_ip`, and
 * every address the source URL and each redirect resolve to. A public address
 * only: loopback, link-local, private and reserved ranges are refused (Docker
 * networks live in the private ones), and so is every address of the Engine
 * itself. The private ranges are allowed only when the operator allows them,
 * with EGRESS_ALLOW_PRIVATE or, for a named host, EGRESS_ALLOW_HOSTS.
 */
class SourceIpPolicy
{
    private const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /** @var ?list<string> */
    private ?array $engineRanges = null;

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

        if ($this->isInternal($ip, null)) {
            return 'source_ip must be a public address: loopback, link-local, private, reserved '
                .'and Docker network addresses are refused.';
        }

        if ($this->isEngines($ip)) {
            return "source_ip is one of this Engine's own addresses.";
        }

        return null;
    }

    /**
     * Whether the Engine must not connect to $ip, one of the addresses $host resolved to.
     */
    public function refuses(string $ip, ?string $host = null): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false
            || $this->isInternal($ip, $host)
            || $this->isEngines($ip);
    }

    private function isInternal(string $ip, ?string $host): bool
    {
        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $public) !== false) {
            return false;
        }

        return ! ($this->allowsPrivate($host) && IpUtils::checkIp($ip, self::PRIVATE_RANGES));
    }

    private function allowsPrivate(?string $host): bool
    {
        return OutboundUrl::allowsPrivate() || ($host !== null && OutboundUrl::allowsHost($host));
    }

    private function isEngines(string $ip): bool
    {
        $this->engineRanges ??= $this->engine->ranges();

        return IpUtils::checkIp($ip, $this->engineRanges);
    }
}
