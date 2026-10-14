<?php

namespace App\System\Firewall;

/**
 * An address the login protection never bans, whatever it gets wrong: an
 * office, a monitoring host, the panel that drives this engine. It is not an
 * allow rule; the firewall's rules still apply to it.
 */
final class TrustedAddress
{
    /** Real line breaks only: \R also matches byte 0x85, which sits inside letters such as ą. */
    private const LINE_BREAK = '/\r\n|\r|\n/';

    public readonly string $address;

    public function __construct(string $address, public readonly ?string $comment = null)
    {
        $this->address = FirewallRule::normalizeAddress($address);
    }

    /**
     * Why a range is too wide to trust: wider than /8 (IPv4) or /16 (IPv6), and
     * /0 above all, it keeps much of the internet from ever being banned.
     */
    public static function widthProblem(string $address): ?string
    {
        [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
        $narrowest = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 8 : 16;
        if ($prefix === null || (int) $prefix >= $narrowest) {
            return null;
        }

        return sprintf('A range wider than /%d would keep much of the internet from ever being banned; trust a narrower one.', $narrowest);
    }

    public function id(): string
    {
        return substr(sha1($this->address), 0, 12);
    }

    /**
     * One per line, `address # comment`; blank lines and `#` lines are skipped.
     *
     * @return list<self>
     */
    public static function parseList(string $text): array
    {
        $list = [];
        foreach (preg_split(self::LINE_BREAK, $text) ?: [] as $line) {
            $entry = self::fromLine(trim($line));
            if ($entry !== null) {
                $list[$entry->id()] ??= $entry;
            }
        }

        return array_values($list);
    }

    /** The entry on an `address # comment` line, or null for a blank, comment or invalid line. */
    private static function fromLine(string $line): ?self
    {
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        [$address, $comment] = array_pad(explode('#', $line, 2), 2, null);
        if (!FirewallRule::isAddress(trim($address))) {
            return null;
        }

        return new self(trim($address), $comment === null || trim($comment) === '' ? null : trim($comment));
    }

    /** @param list<self> $list */
    public static function formatList(array $list): string
    {
        return implode('', array_map(
            static fn (self $e): string => $e->address . ($e->comment === null ? '' : ' # ' . $e->comment) . "\n",
            $list
        ));
    }

    /** @return array{id: string, address: string, comment: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id(), 'address' => $this->address, 'comment' => $this->comment];
    }
}
