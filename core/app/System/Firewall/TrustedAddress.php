<?php

namespace App\System\Firewall;

/**
 * An address the login protection never bans, whatever it gets wrong: an
 * office, a monitoring host, the panel that drives this engine. It is not an
 * allow rule; the firewall's rules still apply to it.
 */
final class TrustedAddress
{
    public readonly string $address;

    public function __construct(string $address, public readonly ?string $comment = null)
    {
        $this->address = FirewallRule::normalizeAddress($address);
    }

    public function id(): string
    {
        return substr(sha1($this->address), 0, 12);
    }

    /** A single address, as fail2ban can lift a ban on; a range cannot be. */
    public function isSingle(): bool
    {
        return !str_contains($this->address, '/');
    }

    /**
     * One per line, `address # comment`; blank lines and `#` lines are skipped.
     *
     * @return list<self>
     */
    public static function parseList(string $text): array
    {
        $list = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$address, $comment] = array_pad(explode('#', $line, 2), 2, null);
            if (!FirewallRule::isAddress(trim($address))) {
                continue;
            }
            $entry = new self(trim($address), $comment === null || trim($comment) === '' ? null : trim($comment));
            $list[$entry->id()] ??= $entry;
        }

        return array_values($list);
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
