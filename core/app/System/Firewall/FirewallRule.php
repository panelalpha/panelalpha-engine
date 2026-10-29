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

    /** fail2ban's bans carry this comment prefix; a deny from an address with it is handled as a ban. */
    public const BAN_PREFIX = 'by Fail2Ban';

    /**
     * What a comment cannot hold: ufw refuses a ' (ufw/parser.py), a comment
     * is one line, and a NUL cannot be passed in a command's argument.
     */
    public const COMMENT_REFUSED_CHARACTERS = '/[\'\r\n\x00]/';

    /**
     * @param  bool  $editable  false for a rule written on the host with options this
     *                          model does not carry (an interface, an application profile,
     *                          a source port, logging): listed as it is, never rewritten
     */
    public function __construct(
        public readonly string $action,
        public readonly string $direction = self::IN,
        public readonly ?string $protocol = null,
        public readonly ?string $port = null,
        public readonly ?string $source = null,
        public readonly ?string $destination = null,
        public readonly ?string $comment = null,
        public readonly bool $editable = true,
        public readonly ?string $raw = null,
        public readonly string $scope = self::HOST,
    ) {}

    /**
     * @param  array{action: string, direction?: ?string, protocol?: ?string, port?: ?string, source?: ?string, destination?: ?string, comment?: ?string, scope?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $direction = self::trimmedOrNull($data['direction'] ?? null) ?? self::IN;
        $port = self::trimmedOrNull($data['port'] ?? null);

        return new self(
            action: $data['action'],
            direction: $direction,
            protocol: self::trimmedOrNull($data['protocol'] ?? null),
            port: $port === null ? null : self::normalizePort($port),
            source: self::addressOrNull($data['source'] ?? null),
            destination: self::addressOrNull($data['destination'] ?? null),
            comment: self::trimmedOrNull($data['comment'] ?? null),
            scope: self::scopeFor(self::trimmedOrNull($data['scope'] ?? null), $data['action'], $direction),
        );
    }

    /**
     * Only a missing scope on an inbound deny becomes `both`; an explicit one is
     * kept, except `both` on an outbound rule, which becomes `host`: nothing
     * published is reached going out.
     */
    private static function scopeFor(?string $scope, string $action, string $direction): string
    {
        $scope ??= $action === self::DENY && $direction !== self::OUT ? self::BOTH : self::HOST;

        return $scope === self::BOTH && $direction === self::OUT ? self::HOST : $scope;
    }

    private static function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function addressOrNull(mixed $value): ?string
    {
        $value = self::trimmedOrNull($value);

        return $value === null ? null : self::normalizeAddress($value);
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
        if (! self::isAddress($address)) {
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
            $mask .= chr($left >= 8 ? 0xFF : ($left <= 0 ? 0 : (0xFF << (8 - $left)) & 0xFF));
        }

        return inet_ntop($packed & $mask).'/'.(int) $prefix;
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
        if ($this->rederivesScope($changes)) {
            $current['scope'] = null;
        }

        return self::fromArray(array_merge($current, array_intersect_key($changes, $current)));
    }

    /**
     * An edit that changes the action or the direction without naming a scope
     * gets the scope a new rule with them would get; a published rule stays published.
     *
     * @param  array<string, mixed>  $changes
     */
    private function rederivesScope(array $changes): bool
    {
        return ! array_key_exists('scope', $changes)
            && ($this->changesAction($changes) || $this->changesDirection($changes))
            && $this->scope !== self::PUBLISHED;
    }

    /** @param  array<string, mixed>  $changes */
    private function changesAction(array $changes): bool
    {
        return isset($changes['action']) && $changes['action'] !== $this->action;
    }

    /**
     * A direction sent as null is cleared to `in`, as on a new rule.
     *
     * @param  array<string, mixed>  $changes
     */
    private function changesDirection(array $changes): bool
    {
        return array_key_exists('direction', $changes)
            && (self::trimmedOrNull($changes['direction']) ?? self::IN) !== $this->direction;
    }

    /**
     * What makes this rule unsafe or unwritable whatever the provider.
     *
     * @param  self|null  $current  the rule this one replaces, on an edit
     * @return array<string, string> field => message
     */
    public function problems(?self $current = null): array
    {
        return array_merge(
            $this->targetProblems(),
            $this->portProblems(),
            $this->scopeProblems(),
            $this->commentProblems($current),
        );
    }

    /**
     * A rule on nothing would allow or drop every connection, and ufw stores
     * "any" as 0.0.0.0/0 and ::/0, so it would take such an address for any.
     *
     * @return array<string, string>
     */
    private function targetProblems(): array
    {
        $problems = [];
        if ($this->port === null && $this->source === null && $this->destination === null) {
            $problems['port'] = 'A rule needs a port, a source or a destination.';
        }
        foreach (['source' => $this->source, 'destination' => $this->destination] as $field => $address) {
            if (in_array($address, ['0.0.0.0/0', '::/0'], true)) {
                $problems[$field] = "The {$field} {$address} is every address; leave it out to match any address.";
            }
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function portProblems(): array
    {
        if ($this->port === null) {
            return [];
        }

        $problems = [];
        $problem = self::portProblem($this->port);
        if ($problem !== null) {
            $problems['port'] = $problem;
        }
        if ($this->protocol === null && preg_match('/[:,]/', $this->port) === 1) {
            $problems['protocol'] = 'A port range or list needs a protocol.';
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function scopeProblems(): array
    {
        $problems = [];
        if ($this->direction === self::BOTH && ($this->source === null || $this->destination !== null)) {
            $problems['source'] = 'A rule in both directions names the address on the other side as source, and no destination.';
        }
        if ($this->scope === self::BOTH && $this->action !== self::DENY) {
            $problems['scope'] = 'Only a deny covers host and published ports together; an allow is for one of them.';
        }
        if ($this->scope === self::PUBLISHED && $this->direction !== self::IN) {
            $problems['direction'] = 'A rule for published ports is for connections coming in.';
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function commentProblems(?self $current): array
    {
        if ($this->comment === null) {
            return [];
        }

        $problem = $this->banPrefixProblem($this->comment, $current) ?? $this->commentProblem($this->comment);

        return $problem === null ? [] : ['comment' => $problem];
    }

    /**
     * A port outside 1-65535, or a range written high to low, which ufw refuses.
     * A leading zero too: ufw stores `08080` as written and iptables-restore then
     * rejects the whole ruleset on reload, which leaves the host dropping every new connection.
     */
    private static function portProblem(string $port): ?string
    {
        foreach (explode(',', $port) as $entry) {
            $problem = self::portEntryProblem(explode(':', $entry, 2));
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** @param list<string> $parts one port, or the two ends of a range */
    private static function portEntryProblem(array $parts): ?string
    {
        foreach ($parts as $part) {
            if (preg_match('/^0\d/', $part)) {
                return 'A port is written without leading zeros.';
            }
        }
        $bounds = array_map('intval', $parts);
        foreach ($bounds as $bound) {
            if ($bound < 1 || $bound > 65535) {
                return 'A port must be between 1 and 65535.';
            }
        }
        if (count($bounds) === 2 && $bounds[1] < $bounds[0]) {
            return 'A port range must not run high to low.';
        }

        return null;
    }

    /**
     * Deleting a rule taken for a ban lifts the ban on its address in fail2ban,
     * so the prefix stays only on a rule that has it already, and on a deny from
     * an address only on a ban of the same address. The comment is compared as
     * normalised (trailing blanks), the ban read from it as stored, as ufw does.
     */
    private function banPrefixProblem(string $comment, ?self $current): ?string
    {
        if (! str_starts_with($comment, self::BAN_PREFIX)) {
            return null;
        }
        $was = $current?->with([]);
        if ($was === null || $comment !== $was->comment) {
            return 'The comment prefix "'.self::BAN_PREFIX.'" marks the bans fail2ban makes.';
        }
        if ($this->isBan() && ! ($current->isBan() && $was->source === $this->source)) {
            return 'A deny from an address with the comment prefix "'.self::BAN_PREFIX.'" is taken for a fail2ban ban; only a ban keeps it, from the address it bans. Change the comment.';
        }

        return null;
    }

    /**
     * ufw checks a rule's words before it takes the comment out of them
     * (ufw/parser.py), so a few comments read as part of the rule, and
     * /usr/sbin/ufw drops --rootdir= and --datadir= arguments before parsing.
     */
    private function commentProblem(string $comment): ?string
    {
        if (str_starts_with($comment, '--rootdir=') || str_starts_with($comment, '--datadir=')) {
            return 'ufw drops a comment that starts with --rootdir= or --datadir= before it reads the rule; reword it.';
        }
        if (in_array($comment, ['in', 'out', 'log', 'log-all'], true)) {
            return "ufw takes the comment \"{$comment}\" for part of the rule; reword it.";
        }

        return $this->scope === self::HOST ? null : self::routeCommentProblem($comment);
    }

    /** A route rule's words are also checked joined by spaces, the comment last. */
    private static function routeCommentProblem(string $comment): ?string
    {
        $route = 'On a rule for published ports, which an incoming deny also is, ufw takes ';
        if ($comment === 'delete') {
            return $route.'the comment "delete" for part of the rule; reword it.';
        }
        $words = ' '.$comment;
        if ((str_contains($words, ' in on ') && str_contains($words, ' out on '))
            || (preg_match('/ (in|out) /', $words) === 1 && preg_match('/ (in|out) on | app (in|out) /', $words) !== 1)) {
            return $route.'the word in or out followed by more words for part of the rule; reword it.';
        }

        return null;
    }

    /**
     * Only a published rule hashes its scope, so a host rule's id does not
     * depend on the scope and a deny keeps its id when it gains its published half.
     */
    public function id(): string
    {
        $key = [$this->action, $this->direction, $this->protocol, $this->port, $this->source, $this->destination];
        if (! $this->editable) {
            $key[] = $this->raw;
        }
        if ($this->scope === self::PUBLISHED) {
            $key[] = $this->scope;
        }

        return substr(sha1((string) json_encode($key)), 0, 12);
    }

    public function managed(): bool
    {
        return $this->comment !== null && str_starts_with($this->comment, self::MANAGED_PREFIX);
    }

    /**
     * A fail2ban ban. fail2ban writes only denies: an allow made from a ban keeps its comment, and handled
     * as a ban, its edit could put it back over a new ban, and fail2ban would be told to lift that one.
     */
    public function isBan(): bool
    {
        return $this->action === self::DENY && $this->source !== null && $this->comment !== null && str_starts_with($this->comment, self::BAN_PREFIX);
    }

    /** Whether some connection $allow lets in is one this rule matches too, with or without its source. */
    public function overlaps(self $allow): bool
    {
        return self::portsOverlap($this->port, $allow->port)
            && in_array($this->scope, [$allow->scope, self::BOTH], true)
            && in_array($this->direction, [$allow->direction, self::BOTH], true)
            && ($this->protocol === null || $allow->protocol === null || $this->protocol === $allow->protocol);
    }

    /** Whether any port of one list falls in a range of the other; null is every port. */
    private static function portsOverlap(?string $ports, ?string $others): bool
    {
        foreach (self::portRanges($ports) as [$a, $b]) {
            foreach (self::portRanges($others) as [$c, $d]) {
                if ($a <= $d && $c <= $b) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{0: int, 1: int}> each entry of the port list as a from-to range */
    private static function portRanges(?string $port): array
    {
        return array_map(static function (string $p): array {
            [$from, $to] = array_pad(explode(':', $p, 2), 2, null);

            return [(int) $from, (int) ($to ?? $from)];
        }, explode(',', $port ?? '1:65535'));
    }

    /** Whether this rule's source takes in $address: no source, or a range holding it. */
    public function hasSource(string $address): bool
    {
        if ($this->source === null) {
            return true;
        }
        [$network, $prefix] = array_pad(explode('/', $this->source, 2), 2, null);
        $net = @inet_pton($network);
        $ip = @inet_pton($address);
        if ($net === false || $ip === false || strlen($net) !== strlen($ip)) {
            return false;
        }
        $bits = $prefix === null ? strlen($ip) * 8 : (int) $prefix;
        $whole = intdiv($bits, 8);
        $mask = (0xFF << (8 - $bits % 8)) & 0xFF;

        return substr($net, 0, $whole) === substr($ip, 0, $whole)
            && ($bits % 8 === 0 || ((ord($net[$whole]) ^ ord($ip[$whole])) & $mask) === 0);
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
            'editable' => $this->editable && ! $this->managed(),
            'raw' => $this->raw,
        ];
    }
}
