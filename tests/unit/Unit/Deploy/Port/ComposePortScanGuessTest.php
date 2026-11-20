<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;

/** A refused port is still the site when nothing but a guess refused it and nothing else is offered. */
class ComposePortScanGuessTest extends TestCase
{
    public function test_a_datastore_port_on_a_built_service_is_offered_when_it_is_the_only_one(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => ['app' => ['build' => '.', 'ports' => ['3306:3306']]]]);

        $this->assertSame([3306], $scan['all']);
        $this->assertSame(3306, $scan['primary']);
        $this->assertSame([], $scan['refused']);
    }

    public function test_a_service_its_env_alone_makes_a_datastore_keeps_its_port(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => ['app' => [
            'image' => 'acme/custom',
            'environment' => ['POSTGRES_PASSWORD' => 'secret', 'POSTGRES_DB' => 'app'],
            'ports' => ['8081:8081'],
        ]]]);

        $this->assertSame([8081], $scan['all']);
        $this->assertSame([], $scan['refused']);
    }

    public function test_an_image_that_is_a_datastore_by_name_is_never_offered(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => ['db' => ['image' => 'postgres:16', 'ports' => ['8081:8081']]]]);

        $this->assertSame([], $scan['all']);
        $this->assertSame([['port' => 8081, 'reason' => 'datastore_image', 'service' => 'postgres:16']], $scan['refused']);
    }
}
