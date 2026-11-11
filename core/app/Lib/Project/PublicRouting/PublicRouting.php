<?php

namespace App\Lib\Project\PublicRouting;

/**
 * What an application's `panelalpha.yaml` asks for beyond its domain's :80/:443: the server's own name,
 * and host ports. A request only: {@see PublicRoutingGrants} decides what is applied.
 */
final class PublicRouting
{
    public const DOMAIN_GENERATED = 'generated';

    public const DOMAIN_HOST = 'host';

    public const DOMAINS = [self::DOMAIN_GENERATED, self::DOMAIN_HOST];

    /**
     * @param  list<PublicPort>  $ports
     */
    public function __construct(
        public readonly string $domain = self::DOMAIN_GENERATED,
        public readonly array $ports = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->domain === self::DOMAIN_GENERATED && $this->ports === [];
    }

    public function wantsHostDomain(): bool
    {
        return $this->domain === self::DOMAIN_HOST;
    }

    /** @return array{domain: string, ports: list<array{listen: int, to: int, transport: string}>} */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'ports' => array_map(static fn (PublicPort $port): array => $port->toArray(), $this->ports),
        ];
    }

    /** As {@see toArray()} wrote it; anything unreadable asks for nothing. */
    public static function fromArray(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::none();
        }
        $domain = in_array($stored['domain'] ?? null, self::DOMAINS, true) ? $stored['domain'] : self::DOMAIN_GENERATED;
        $ports = array_values(array_filter(array_map(
            PublicPort::fromArray(...),
            is_array($stored['ports'] ?? null) ? $stored['ports'] : []
        )));

        return new self($domain, $ports);
    }
}
