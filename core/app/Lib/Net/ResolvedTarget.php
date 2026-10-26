<?php

namespace App\Lib\Net;

/**
 * A URL that passed the egress guard, with the addresses it resolved to and the
 * one the connection must be pinned to.
 *
 * A literal address has nothing to resolve: the address list is empty and
 * {@see pin()} is null.
 */
final class ResolvedTarget
{
    /**
     * @param  string  $url  the URL that was checked
     * @param  string  $host  the host as written, without brackets
     * @param  int  $port  the effective port
     * @param  list<string>  $addresses  the A and AAAA answers that were checked
     */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
    ) {}

    /** The address the connection should use, or null when there is nothing to pin. */
    public function address(): ?string
    {
        return $this->addresses[0] ?? null;
    }

    /**
     * A `host:port:address` value for curl's `--resolve` and git's
     * `http.curloptResolve`, so a second lookup cannot answer differently.
     */
    public function pin(): ?string
    {
        $address = $this->address();
        if ($address === null) {
            return null;
        }

        return $this->host.':'.$this->port.':'.self::bracketed($address);
    }

    private static function bracketed(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }
}
