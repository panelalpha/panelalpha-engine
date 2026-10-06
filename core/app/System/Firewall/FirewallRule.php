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
 *
 * Scope `host` is the host's own ports; `published` is the ports Docker
 * publishes, matched on the container's port and address (ufw's route rules).
 * Neither reaches the other. Scope `both` is a deny written as one of each,
 * as a ban is: a deny made here without a scope is one, so blocking an
 * address closes the engine's published ports (2011, FTP, SFTP) as well.
 */
final class FirewallRule
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const IN = 'in';
    public const OUT = 'out';
    public const BOTH = 'both';
    public const HOST = 'host';
    public const PUBLISHED = 'published';

    /** Rules the installer opens carry this comment prefix; the API leaves them alone. */
    public const MANAGED_PREFIX = 'panelalpha:';

    /**
     * What a comment cannot hold: ufw refuses a ' (ufw/parser.py), a comment
     * is one line, and a NUL cannot be passed in a command's argument.
     */
    public const COMMENT_REFUSED_CHARACTERS = '/[\'\r\n\x00]/';

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
        public readonly string $scope = self::HOST,
    ) {
    }

    /**
     * @param array{action: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string, scope?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        $blank = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $address = static fn (mixed $v): ?string => ($v = $blank($v)) === null ? null : self::normalizeAddress($v);

        $direction = $blank($data['direction'] ?? null) ?? self::IN;
        $scope = $blank($data['scope'] ?? null) ?? self::HOST;
        // An outbound deny has nothing published to close.
        if ($data['action'] === self::DENY && $scope === self::HOST && $direction !== self::OUT) {
            $scope = self::BOTH;
        } elseif ($scope === self::BOTH && $direction === self::OUT) {
            $scope = self::HOST;
        }

        return new self(
            action: $data['action'],
            direction: $direction,
            protocol: $blank($data['protocol'] ?? null),
            port: ($port = $blank($data['port'] ?? null)) === null ? null : self::normalizePort($port),
            source: $address($data['source'] ?? null),
            destination: $address($data['destination'] ?? null),
            comment: $blank($data['comment'] ?? null),
            scope: $scope,
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

    /**
     * A port list in the order ufw stores it, ranges after the port they
     * start at: 443,80 is 80,443, and is the same rule.
     */
    public static function normalizePort(string $port): string
    {
        $ports = explode(',', $port);
        $key = static function (string $p): array {
            [$from, $to] = array_pad(explode(':', $p, 2), 2, null);

            return [(int) $from, $to === null ? 0 : 1, (int) $to];
        };
        usort($ports, static fn (string $a, string $b): int => $key($a) <=> $key($b));

        return implode(',', $ports);
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
            'scope' => $this->scope,
        ];
        /** @var array{action: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string, scope?: ?string} $merged */
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
        // ufw stores "any" as these, so it would take the rule for the one on any address.
        foreach (['source' => $this->source, 'destination' => $this->destination] as $field => $address) {
            if (in_array($address, ['0.0.0.0/0', '::/0'], true)) {
                $problems[$field] = "The {$field} {$address} is every address; leave it out to match any address.";
            }
        }
        if ($this->port !== null && $this->protocol === null && preg_match('/[:,]/', $this->port) === 1) {
            $problems['protocol'] = 'A port range or list needs a protocol.';
        }
        if ($this->direction === self::BOTH && ($this->source === null || $this->destination !== null)) {
            $problems['source'] = 'A rule in both directions names the address on the other side as source, and no destination.';
        }
        if ($this->scope === self::BOTH && $this->action !== self::DENY) {
            $problems['scope'] = 'Only a deny covers host and published ports together; an allow is for one of them.';
        }
        if ($this->scope === self::PUBLISHED && $this->direction !== self::IN) {
            $problems['direction'] = 'A rule for published ports is for connections coming in.';
        }
        if ($this->comment !== null && ($problem = $this->commentProblem($this->comment)) !== null) {
            $problems['comment'] = $problem;
        }

        return $problems;
    }

    /**
     * ufw checks a rule's words before it takes the comment out of them
     * (ufw/parser.py), so a few comments read as part of the rule. A route
     * rule's words are also checked joined by spaces, the comment last.
     */
    private function commentProblem(string $comment): ?string
    {
        // /usr/sbin/ufw drops these arguments before it parses the rule.
        if (str_starts_with($comment, '--rootdir=') || str_starts_with($comment, '--datadir=')) {
            return 'ufw drops a comment that starts with --rootdir= or --datadir= before it reads the rule; reword it.';
        }
        if (in_array($comment, ['in', 'out', 'log', 'log-all'], true)) {
            return "ufw takes the comment \"{$comment}\" for part of the rule; reword it.";
        }
        if ($this->scope === self::HOST) {
            return null;
        }
        $route = 'On a rule for published ports, which an incoming deny also is, ufw takes ';
        if ($comment === 'delete') {
            return $route . 'the comment "delete" for part of the rule; reword it.';
        }
        $words = ' ' . $comment;
        if ((str_contains($words, ' in on ') && str_contains($words, ' out on '))
            || (preg_match('/ (in|out) /', $words) === 1 && preg_match('/ (in|out) on | app (in|out) /', $words) !== 1)) {
            return $route . 'the word in or out followed by more words for part of the rule; reword it.';
        }

        return null;
    }

    public function id(): string
    {
        $key = [$this->action, $this->direction, $this->protocol, $this->port, $this->source, $this->destination];
        if (!$this->editable) {
            $key[] = $this->raw;
        }
        // Host rules keep the ids they had before rules had a scope, and a
        // deny keeps its id when it gains its published half.
        if ($this->scope === self::PUBLISHED) {
            $key[] = $this->scope;
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
     * @return array{id: string, scope: string, action: string, direction: string, protocol: ?string, port: ?string, source: ?string, destination: ?string, comment: ?string, managed: bool, editable: bool, raw: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'scope' => $this->scope,
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
