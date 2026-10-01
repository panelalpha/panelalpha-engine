<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\PortMapping;
use PHPUnit\Framework\TestCase;

/**
 * One entry of a Compose `ports:` list, read as the host port it publishes.
 *
 * This is what the proxy gets pointed at, so every form Compose accepts has
 * to parse to the same thing, and a binding that publishes to loopback has
 * to parse to nothing at all - a service only the host can reach is not the
 * app's front door.
 */
class PortMappingTest extends TestCase
{
    public function test_a_bare_integer_publishes_that_port(): void
    {
        $mapping = PortMapping::parse(8080);

        $this->assertSame(8080, $mapping->hostPort);
        $this->assertNull($mapping->containerPort);
    }

    public function test_a_host_and_container_pair(): void
    {
        $mapping = PortMapping::parse('8080:80');

        $this->assertSame(8080, $mapping->hostPort);
        $this->assertSame(80, $mapping->containerPort);
    }

    public function test_a_protocol_suffix_is_ignored(): void
    {
        $mapping = PortMapping::parse('8080:80/tcp');

        $this->assertSame(8080, $mapping->hostPort);
        $this->assertSame(80, $mapping->containerPort);
    }

    public function test_a_bind_address_is_dropped(): void
    {
        $mapping = PortMapping::parse('0.0.0.0:8080:80');

        $this->assertSame(8080, $mapping->hostPort);
        $this->assertSame(80, $mapping->containerPort);
    }

    public function test_a_loopback_binding_publishes_nothing(): void
    {
        // Reachable only from inside the container's host. Pointing the proxy
        // at it produces a connection refused on every request.
        foreach (['127.0.0.1:8080:80', 'localhost:8080:80', '::1:8080:80'] as $entry) {
            $this->assertNull(PortMapping::parse($entry), $entry);
        }
    }

    public function test_an_env_var_default_is_resolved_first(): void
    {
        $mapping = PortMapping::parse('${APP_PORT:-8090}:8000');

        $this->assertSame(8090, $mapping->hostPort);
        $this->assertSame(8000, $mapping->containerPort);
    }

    public function test_an_env_var_with_no_default_is_not_a_mapping(): void
    {
        $this->assertNull(PortMapping::parse('${APP_PORT}:8000'));
    }

    public function test_a_zero_or_negative_port_is_not_a_mapping(): void
    {
        $this->assertNull(PortMapping::parse(0));
        $this->assertNull(PortMapping::parse(-1));
        $this->assertNull(PortMapping::parse('0:80'));
    }

    public function test_a_non_scalar_entry_is_not_a_mapping(): void
    {
        $this->assertNull(PortMapping::parse(null));
        $this->assertNull(PortMapping::parse(['published' => 8080]));
    }

    /** PhantomBot publishes with the long syntax and was routed to 8080. */
    public function test_the_long_syntax_is_a_mapping(): void
    {
        $mapping = PortMapping::parse(['target' => 25000, 'published' => 25000, 'protocol' => 'tcp']);
        $this->assertSame(25000, $mapping->hostPort);
        $this->assertSame(25000, $mapping->containerPort);

        $mapping = PortMapping::parse(['target' => '80', 'published' => '${WEB_PORT:-8081}']);
        $this->assertSame(8081, $mapping->hostPort);
        $this->assertSame(80, $mapping->containerPort);

        $mapping = PortMapping::parse(['target' => 3000]);
        $this->assertSame(3000, $mapping->hostPort);
        $this->assertNull($mapping->containerPort);
    }

    public function test_a_long_syntax_loopback_binding_publishes_nothing(): void
    {
        $this->assertNull(PortMapping::parse(['target' => 80, 'published' => 8080, 'host_ip' => '127.0.0.1']));
        $this->assertNull(PortMapping::parse(['target' => 80, 'published' => 8080, 'host_ip' => '::1']));
        $this->assertSame(8080, PortMapping::parse(['target' => 80, 'published' => 8080, 'host_ip' => '0.0.0.0'])->hostPort);
    }

    public function test_surrounding_whitespace_is_tolerated(): void
    {
        $this->assertSame(8080, PortMapping::parse('  8080:80  ')->hostPort);
    }

    /**
     * The end-to-end consequence of the empty-default fix: mailcow's web
     * ports parse instead of being discarded, while a loopback publish is
     * still deliberately dropped.
     */
    public function test_an_empty_bind_default_still_yields_the_port(): void
    {
        $mapping = PortMapping::parse('${HTTPS_BIND:-}:${HTTPS_PORT:-443}:${HTTPS_PORT:-443}');

        $this->assertNotNull($mapping);
        $this->assertSame(443, $mapping->hostPort);
        $this->assertSame(443, $mapping->containerPort);
    }

    public function test_a_loopback_publish_is_still_discarded(): void
    {
        $this->assertNull(PortMapping::parse('${DOVEADM_PORT:-127.0.0.1:19991}:12345'));
    }
}
