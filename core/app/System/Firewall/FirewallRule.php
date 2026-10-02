<?php

namespace App\System\Firewall;

/**
 * One rule, in terms every provider can express. A null protocol, port,
 * source or destination means "any".
 *
 * The id is derived from what the rule matches, not from its position, so it
 * stays valid while other rules are added or removed.
 *
 * Direction `both` is one rule for traffic with an address either way, as a
 * bare address in csf.allow/csf.deny was: from `source` coming in, and to it
 * going out. A provider writes it as the two rules it takes.
 */
final class FirewallRule
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const IN = 'in';
    public const OUT = 'out';
    public const BOTH = 'both';

    /** Rules the installer opens carry this comment prefix; the API leaves them alone. */
    public const MANAGED_PREFIX = 'panelalpha:';

    public function __construct(
        public readonly string $action,
        public readonly string $direction = self::IN,
        public readonly ?string $protocol = null,
        public readonly ?string $port = null,
        public readonly ?string $source = null,
        public readonly ?string $destination = null,
        public readonly ?string $comment = null,
        // A rule written on the host with options this model does not carry
        // (an interface, an application profile, a source port, logging).
        // Listed as it is, never rewritten from a lossy copy.
        public readonly bool $editable = true,
        public readonly ?string $raw = null,
    ) {
    }

    /**
     * @param array{action: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        $blank = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $address = static fn (mixed $v): ?string => ($v = $blank($v)) === null ? null : self::normalizeAddress($v);

        return new self(
            action: $data['action'],
            direction: $blank($data['direction'] ?? null) ?? self::IN,
            protocol: $blank($data['protocol'] ?? null),
            port: $blank($data['port'] ?? null),
            source: $address($data['source'] ?? null),
            destination: $address($data['destination'] ?? null),
            comment: $blank($data['comment'] ?? null),
        );
    }

    /** An IPv4 or IPv6 address, or a CIDR range. Hostnames are not rules. */
    public static function isAddress(string $target): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $target, 2), 2, null);
        $bits = match (true) {
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => 0,
        };
        if ($bits === 0) {
            return false;
        }

        return $prefix === null || (preg_match('/\A[0-9]{1,3}\z/', $prefix) === 1 && (int) $prefix <= $bits);
    }

    /**
     * The spelling firewalls store, so a rule is found again under the id it
     * was added with: 1.2.3.4/32 is 1.2.3.4, 10.1.2.3/8 is 10.0.0.0/8, and
     * IPv6 is compressed. Anything that is not an address is left as it is.
     */
    public static function normalizeAddress(string $address): string
    {
        if (!self::isAddress($address)) {
            return $address;
        }
        [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
        $packed = (string) inet_pton($ip);
        $bits = strlen($packed) * 8;
        if ($prefix === null || (int) $prefix === $bits) {
            return (string) inet_ntop($packed);
        }

        $mask = '';
        for ($i = 0, $left = (int) $prefix; $i < strlen($packed); $i++, $left -= 8) {
            $mask .= chr($left >= 8 ? 0xff : ($left <= 0 ? 0 : (0xff << (8 - $left)) & 0xff));
        }

        return inet_ntop($packed & $mask) . '/' . (int) $prefix;
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $current = [
            'action' => $this->action,
            'direction' => $this->direction,
            'protocol' => $this->protocol,
            'port' => $this->port,
            'source' => $this->source,
            'destination' => $this->destination,
            'comment' => $this->comment,
        ];
        /** @var array{action: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string} $merged */
        $merged = array_merge($current, array_intersect_key($changes, $current));

        return self::fromArray($merged);
    }

    /**
     * What makes this rule unsafe or unwritable whatever the provider.
     *
     * @return array<string, string> field => message
     */
    public function problems(): array
    {
        $problems = [];
        // A rule on nothing would allow or drop every connection.
        if ($this->port === null && $this->source === null && $this->destination === null) {
            $problems['port'] = 'A rule needs a port, a source or a destination.';
        }
        if ($this->port !== null && $this->protocol === null && preg_match('/[:,]/', $this->port) === 1) {
            $problems['protocol'] = 'A port range or list needs a protocol.';
        }
        if ($this->direction === self::BOTH && ($this->source === null || $this->destination !== null)) {
            $problems['source'] = 'A rule in both directions names the address on the other side as source, and no destination.';
        }

        return $problems;
    }

    public function id(): string
    {
        $key = [$this->action, $this->direction, $this->protocol, $this->port, $this->source, $this->destination];
        if (!$this->editable) {
            $key[] = $this->raw;
        }

        return substr(sha1((string) json_encode($key)), 0, 12);
    }

    public function managed(): bool
    {
        return $this->comment !== null && str_starts_with($this->comment, self::MANAGED_PREFIX);
    }

    /** Whether both match the same traffic the same way; the comment does not count. */
    public function sameMatch(self $other): bool
    {
        return $this->id() === $other->id();
    }

    /**
     * @return array{id: string, action: string, direction: string, protocol: ?string, port: ?string, source: ?string, destination: ?string, comment: ?string, managed: bool, editable: bool, raw: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'action' => $this->action,
            'direction' => $this->direction,
            'protocol' => $this->protocol,
            'port' => $this->port,
            'source' => $this->source,
            'destination' => $this->destination,
            'comment' => $this->comment,
            'managed' => $this->managed(),
            'editable' => $this->editable && !$this->managed(),
            'raw' => $this->raw,
        ];
    }
}
