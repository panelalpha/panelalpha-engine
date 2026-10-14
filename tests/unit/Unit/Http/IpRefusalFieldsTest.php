<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * IP refusals used to come back keyed under `0`, which no client can place on
 * a field. Each now names the request field it is about, and the one about the
 * subnet's state is a 409 with no field.
 */
class IpRefusalFieldsTest extends TestCase
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
        foreach (['ip_assigned', 'ip_subnets', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_mask_out_of_range_is_on_mask(): void
    {
        $this->postJson('/api/ip/subnets', ['ip' => '192.0.2.0', 'mask' => 33])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['mask' => ['IPv4 mask must be between 0 and 32.']]);
    }

    public function test_an_address_that_is_not_the_network_is_on_ip(): void
    {
        $this->postJson('/api/ip/subnets', ['ip' => '192.0.2.1', 'mask' => 24])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ip' => ['IPv4 must be the network address (e.g. 192.0.2.0/24).']]);
    }

    public function test_a_subnet_that_exists_is_on_ip(): void
    {
        $this->subnet();

        $this->postJson('/api/ip/subnets', ['ip' => '192.0.2.0', 'mask' => 29])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ip' => ['Subnet 192.0.2.0/29 already exist.']]);
    }

    public function test_an_assignment_without_a_project_is_on_user_id(): void
    {
        $subnet = $this->subnet();

        $this->postJson('/api/ip/assign', ['ip_subnet_id' => $subnet->id, 'ip_address' => '192.0.2.2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['user_id' => ['user_id or username is required.']]);
    }

    public function test_a_project_that_does_not_exist_is_on_the_field_that_named_it(): void
    {
        $subnet = $this->subnet();

        $this->postJson('/api/ip/assign', ['username' => 'nobody', 'ip_subnet_id' => $subnet->id, 'ip_address' => '192.0.2.2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['username' => ['Project not found.']]);
        $this->postJson('/api/ip/assign', ['user_id' => 999, 'ip_subnet_id' => $subnet->id, 'ip_address' => '192.0.2.2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['user_id' => ['Project not found.']]);
    }

    public function test_a_subnet_that_does_not_exist_is_on_ip_subnet_id(): void
    {
        $this->makeUser('alice');

        $this->postJson('/api/ip/unassign', ['username' => 'alice', 'ip_subnet_id' => 999, 'ip_address' => '192.0.2.2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ip_subnet_id' => ['IP subnet not found.']]);
    }

    public function test_an_address_outside_the_subnet_is_on_ip_address(): void
    {
        $this->makeUser('alice');
        $subnet = $this->subnet();

        $this->postJson('/api/ip/assign', ['username' => 'alice', 'ip_subnet_id' => $subnet->id, 'ip_address' => '198.51.100.1'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ip_address' => ['IP address 198.51.100.1 is not within the selected subnet 192.0.2.0/29.']]);
    }

    public function test_releasing_an_address_that_is_not_assigned_is_on_ip_address(): void
    {
        $this->makeUser('alice');
        $subnet = $this->subnet();

        $this->postJson('/api/ip/unassign', ['username' => 'alice', 'ip_subnet_id' => $subnet->id, 'ip_address' => '192.0.2.2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['ip_address' => ['IP address is not assigned to the user.']]);
    }

    public function test_deleting_a_subnet_that_is_in_use_is_a_conflict(): void
    {
        $user = $this->makeUser('alice');
        $subnet = $this->subnet();
        IpAssigned::create(['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => '192.0.2.2']);

        $this->deleteJson("/api/ip/subnets/{$subnet->id}")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Cannot delete IP subnet with assigned IP addresses']);
        $this->assertSame(1, IpSubnet::query()->count());
    }

    private function subnet(): IpSubnet
    {
        return IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
    }
}
