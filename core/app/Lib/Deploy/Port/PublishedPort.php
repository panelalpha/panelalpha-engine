<?php

namespace App\Lib\Deploy\Port;

/**
 * The `ports:` entry of a generated compose file's app service, as the two
 * numbers it is made of.
 *
 * Only the container side is ever rewritten. The published side is what proxy
 * rules and firewall openings were built around, so moving it would break
 * both to fix a mismatch that is entirely inside the container.
 *
 * Distinct from {@see PortMapping}, which reads the ports of an arbitrary
 * compose file to work out what a project offers. This one is about a file
 * the engine wrote itself, where the shape is known and the question is only
 * what to change it to.
 */
final class PublishedPort
{
    private function __construct(
        /** The host-side port; never moved. */
        public readonly int $published,
        /** The container-side port the recipe guessed. */
        public readonly int $container
    ) {}

    /**
     * Null when the entry is not a pair of usable ports — a range, an
     * interface binding, or a value an interpolation left empty. The caller
     * leaves anything it cannot read alone rather than guessing at it.
     */
    public static function parse(string $entry): ?self
    {
        [$published, $container] = array_pad(explode(':', $entry, 2), 2, null);
        $published = (int) $published;
        // A bare "8080" publishes and serves the same port.
        $container = (int) ($container ?? $published);

        return $published > 0 && $container > 0 ? new self($published, $container) : null;
    }

    /** The same entry, forwarding to the port the application really bound. */
    public function forwardingTo(int $actual): string
    {
        return "{$this->published}:{$actual}";
    }
}
