<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\PrimaryPortBinding;
use PHPUnit\Framework\TestCase;

/** The detected primary port is published on the service that only exposes it. */
class PrimaryPortBindingTest extends TestCase
{
    public function test_the_port_is_published_on_the_service_that_offers_it_and_no_other(): void
    {
        $compose = ['services' => [
            'x-common' => 'anchor',
            'db' => ['image' => 'postgres:16'],
            'app' => ['image' => 'acme/app', 'expose' => ['8080']],
        ]];

        $bound = PrimaryPortBinding::apply($compose);

        $this->assertSame(8080, $bound['published']);
        $this->assertSame(['8080:8080'], $bound['compose']['services']['app']['ports']);
        $this->assertSame(['image' => 'postgres:16'], $bound['compose']['services']['db']);
        $this->assertSame('anchor', $bound['compose']['services']['x-common']);
    }

    public function test_an_unparseable_entry_is_skipped_on_the_way_to_the_one_that_offers_the_port(): void
    {
        $compose = ['services' => ['app' => ['image' => 'acme/app', 'expose' => ['not-a-port', '8080']]]];

        $bound = PrimaryPortBinding::apply($compose);

        $this->assertSame(8080, $bound['published']);
        $this->assertSame(['8080:8080'], $bound['compose']['services']['app']['ports']);
    }
}
