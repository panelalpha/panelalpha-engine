<?php

namespace Tests\Unit\Project\PublicRouting;

use App\Lib\Project\PublicRouting\PublicPort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A host port as the project's record stores it: read back only when every field has its type, else nothing.
 */
class PublicPortTest extends TestCase
{
    public function test_a_stored_port_reads_back_as_it_was_written(): void
    {
        $port = PublicPort::fromArray((new PublicPort(5353, 53, PublicPort::UDP))->toArray());

        $this->assertNotNull($port);
        $this->assertSame(['listen' => 5353, 'to' => 53, 'transport' => PublicPort::UDP], $port->toArray());
        $this->assertSame('udp/5353', $port->key());
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadable(): iterable
    {
        yield 'not an array' => ['tcp/2222'];
        yield 'null' => [null];
        yield 'a listen port given as a string' => [['listen' => '2222', 'to' => 22, 'transport' => PublicPort::TCP]];
        yield 'no target port' => [['listen' => 2222, 'transport' => PublicPort::TCP]];
        yield 'an unknown transport' => [['listen' => 2222, 'to' => 22, 'transport' => 'sctp']];
        yield 'no transport' => [['listen' => 2222, 'to' => 22]];
    }

    #[DataProvider('unreadable')]
    public function test_anything_else_reads_as_no_port(mixed $stored): void
    {
        $this->assertNull(PublicPort::fromArray($stored));
    }
}
