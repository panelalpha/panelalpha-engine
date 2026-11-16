<?php

namespace App\Lib\Deploy\Port;

/**
 * The `ports:` entry of the engine's own generated app service; only the container side is ever
 * rewritten. {@see PortMapping} reads arbitrary compose files instead.
 */
final class PublishedPort
{
    private function __construct(
        /** The host-side port; never moved. */
        public readonly int $published,
        /** The container-side port the recipe guessed. */
        public readonly int $container
    ) {}

    /** Null when the entry is not a pair of usable ports; a bare "8080" publishes and serves the same port. */
    public static function parse(string $entry): ?self
    {
        [$published, $container] = array_pad(explode(':', $entry, 2), 2, null);
        $published = (int) $published;
        $container = (int) ($container ?? $published);

        return $published > 0 && $container > 0 ? new self($published, $container) : null;
    }

    /** The same entry, forwarding to the port the application really bound. */
    public function forwardingTo(int $actual): string
    {
        return "{$this->published}:{$actual}";
    }
}
