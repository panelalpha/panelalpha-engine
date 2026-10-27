<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\ProjectHostnames;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class ProjectHostnamesTest extends TestCase
{
    use InMemoryDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->alice = $this->makeUser('alice', [], 'shop.example.test');
        $shop = $this->makeMainDomain($this->alice, 'shop.example.test');
        $shop->addAlias('www.shop.example.test');
        $shop->save();
    }

    public function test_a_project_answers_to_its_domains_aliases_and_tunnel_hostnames(): void
    {
        $this->tunnel($this->alice, 'shop.example.test', 'Live.Example.Test');

        $this->assertEqualsCanonicalizing(
            ['shop.example.test', 'www.shop.example.test', 'live.example.test'],
            ProjectHostnames::of($this->alice)
        );
    }

    public function test_a_zone_with_only_this_projects_hostnames_is_not_shared(): void
    {
        $this->assertFalse(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    public function test_another_projects_domain_in_the_zone_shares_it(): void
    {
        $bob = $this->makeUser('bob', [], 'blog.example.test');
        $this->makeMainDomain($bob, 'blog.example.test');

        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    public function test_another_projects_alias_in_the_zone_shares_it(): void
    {
        $bob = $this->makeUser('bob', [], 'bob.other.test');
        $domain = $this->makeMainDomain($bob, 'bob.other.test');
        $domain->addAlias('example.test');
        $domain->save();

        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    public function test_another_projects_tunnel_hostname_in_the_zone_shares_it(): void
    {
        $bob = $this->makeUser('bob', [], 'bob.other.test');
        $this->makeMainDomain($bob, 'bob.other.test');
        $this->tunnel($bob, 'bob.other.test', 'bob.example.test');

        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    public function test_the_projects_own_staging_copy_does_not_share_the_zone(): void
    {
        $staging = $this->makeStagingOf($this->alice, 'alicestg', 'staging.shop.example.test');

        $this->assertFalse(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
        $this->assertFalse(ProjectHostnames::zoneServesAnotherProject($staging, 'example.test'));
    }

    public function test_an_unrelated_project_still_shares_the_zone_with_a_staging_copy(): void
    {
        $staging = $this->makeStagingOf($this->alice, 'alicestg', 'staging.shop.example.test');
        $bob = $this->makeUser('bob', [], 'blog.example.test');
        $this->makeMainDomain($bob, 'blog.example.test');

        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($staging, 'example.test'));
    }

    public function test_the_staging_copy_of_an_unrelated_project_shares_the_zone(): void
    {
        $bob = $this->makeUser('bob', [], 'bob.other.test');
        $this->makeMainDomain($bob, 'bob.other.test');
        $this->makeStagingOf($bob, 'bobstg', 'staging.example.test');

        $this->assertTrue(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    public function test_a_name_that_only_ends_with_the_zones_letters_is_not_in_it(): void
    {
        $bob = $this->makeUser('bob', [], 'notexample.test');
        $this->makeMainDomain($bob, 'notexample.test');

        $this->assertFalse(ProjectHostnames::zoneServesAnotherProject($this->alice, 'example.test'));
    }

    private function makeStagingOf(User $live, string $username, string $domain): User
    {
        $staging = $this->makeUser($username, [], $domain);
        $staging->staging = $live->id;
        $staging->save();
        $this->makeMainDomain($staging, $domain);

        return $staging;
    }

    private function tunnel(User $user, string $domain, string $hostname): void
    {
        $domainModel = Domain::findByName($domain);
        $this->assertNotNull($domainModel);

        $tunnel = new Tunnel;
        $tunnel->user_id = $user->id;
        $tunnel->domain_id = $domainModel->id;
        $tunnel->provider = Tunnel::PROVIDER_CLOUDFLARE;
        $tunnel->hostname = $hostname;
        $tunnel->save();
    }
}
