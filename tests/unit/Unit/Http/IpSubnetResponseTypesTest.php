<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\IpSubnet;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A subnet is serialised straight from its columns, so the shared flag only
 * reaches a client as a boolean once the model casts it.
 */
class IpSubnetResponseTypesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ip_subnets');
        parent::tearDown();
    }

    public function test_the_listing_reports_the_shared_flag_as_a_real_boolean(): void
    {
        $shared = TestNetwork::public3(0);
        $dedicated = TestNetwork::public2(0);
        IpSubnet::create(['ip' => $shared, 'mask' => 24, 'family' => 4, 'is_shared' => 1]);
        IpSubnet::create(['ip' => $dedicated, 'mask' => 24, 'family' => 4, 'is_shared' => 0]);

        $response = $this->getJson('/api/ip/subnets')->assertOk();

        $subnets = collect($response->json('data'))->keyBy('ip');
        $this->assertTrue($subnets[$shared]['is_shared']);
        $this->assertFalse($subnets[$dedicated]['is_shared']);
    }

    public function test_the_listing_reports_the_prefix_length_as_an_integer(): void
    {
        IpSubnet::create(['ip' => '2001:db8::', 'mask' => 64, 'family' => 6, 'is_shared' => 0]);

        $response = $this->getJson('/api/ip/subnets')->assertOk();

        $this->assertSame(64, $response->json('data.0.mask'));
    }
}
