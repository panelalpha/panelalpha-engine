<?php

namespace Tests\Unit\Proxy;

use App\Lib\Helpers\UpstreamSpec;
use Tests\TestCase;

class UpstreamSpecTest extends TestCase
{
    public function test_port_only_uses_default_host(): void
    {
        [$host, $port] = UpstreamSpec::parse('8080', 'multiportapp');
        $this->assertSame('multiportapp', $host);
        $this->assertSame(8080, $port);
    }

    public function test_host_port_is_parsed(): void
    {
        [$host, $port] = UpstreamSpec::parse('other:9000', 'multiportapp');
        $this->assertSame('other', $host);
        $this->assertSame(9000, $port);
    }

    public function test_invalid_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpstreamSpec::parse('not-a-port', 'multiportapp');
    }

    public function test_empty_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpstreamSpec::parse('  ', 'multiportapp');
    }
}
