<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\PublishedPort;
use PHPUnit\Framework\TestCase;

/**
 * The `ports:` entry of a generated compose file, and the one edit the engine
 * is allowed to make to it.
 *
 * The published side is what proxy rules and firewall openings were built
 * around. Moving it to fix a mismatch that lives entirely inside the
 * container would break both, so only the container side ever changes.
 */
class PublishedPortTest extends TestCase
{
    public function test_it_reads_a_published_and_a_container_port(): void
    {
        $mapping = PublishedPort::parse('8080:3000');

        $this->assertSame(8080, $mapping?->published);
        $this->assertSame(3000, $mapping?->container);
    }

    public function test_a_bare_port_publishes_and_serves_the_same_number(): void
    {
        $mapping = PublishedPort::parse('8080');

        $this->assertSame(8080, $mapping?->published);
        $this->assertSame(8080, $mapping?->container);
    }

    public function test_only_the_container_side_moves(): void
    {
        $mapping = PublishedPort::parse('8080:3000');

        $this->assertSame('8080:8090', $mapping?->forwardingTo(8090));
    }

    /**
     * Anything unreadable is left alone rather than guessed at: rewriting an
     * entry we did not understand is how a working mapping gets broken.
     */
    public function test_an_entry_that_is_not_two_ports_is_refused(): void
    {
        $this->assertNull(PublishedPort::parse(''));
        $this->assertNull(PublishedPort::parse('not-a-port'));
        $this->assertNull(PublishedPort::parse('0:3000'));
        $this->assertNull(PublishedPort::parse('8080:0'));
        $this->assertNull(PublishedPort::parse(':3000'));
    }

    /**
     * An interface binding has three parts. Reading the first as the
     * published port would rewrite the entry into nonsense, so it is refused.
     */
    public function test_an_interface_binding_is_refused(): void
    {
        // explode with a limit of 2 leaves "0.0.0.0" as the published side.
        $this->assertNull(PublishedPort::parse('0.0.0.0:8080:3000'));
    }

    public function test_an_unresolved_interpolation_is_refused(): void
    {
        $this->assertNull(PublishedPort::parse(':'));
    }
}
