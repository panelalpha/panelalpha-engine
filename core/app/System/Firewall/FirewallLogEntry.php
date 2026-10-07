<?php

namespace App\System\Firewall;

/** One thing the firewall did: a connection it blocked, or an address banned or released. */
final class FirewallLogEntry
{
    public const BLOCKED = 'blocked';
    public const BAN = 'ban';
    public const UNBAN = 'unban';

    public function __construct(
        public readonly \DateTimeImmutable $time,
        public readonly string $type,
        // The other side: where a blocked connection came from (or went to), or who was banned.
        public readonly string $address,
        public readonly ?string $direction = null,
        public readonly ?string $protocol = null,
        public readonly ?string $port = null,
        // The fail2ban jail, for a ban.
        public readonly ?string $jail = null,
    ) {
    }

    /**
     * @return array{time: string, type: string, address: string, direction: ?string, protocol: ?string, port: ?string, jail: ?string}
     */
    public function toArray(): array
    {
        return [
            'time' => $this->time->format(\DateTimeInterface::ATOM),
            'type' => $this->type,
            'address' => $this->address,
            'direction' => $this->direction,
            'protocol' => $this->protocol,
            'port' => $this->port,
            'jail' => $this->jail,
        ];
    }
}
