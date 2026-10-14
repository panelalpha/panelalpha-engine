<?php

namespace Tests\Unit\Domains;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\NewDomain;
use App\Models\Domain;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A subdomain records its parent when it is created, so the parent cannot be deleted under it. */
class SubdomainParentTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_a_new_subdomain_starts_with_its_parent_and_another_domain_without_one(): void
    {
        $this->assertSame('shop.test', NewDomain::details('a.shop.test', false, [], 'shop.test')['parent_domain']);
        $this->assertArrayNotHasKey('parent_domain', NewDomain::details('shop.test', false, []));
    }

    public function test_deleting_a_parent_that_still_has_a_subdomain_is_refused(): void
    {
        $user = $this->makeUser('alice');
        $this->createDomain($user, 'shop.test', 'addon', null);
        $this->createDomain($user, 'a.shop.test', 'sub', 'shop.test');

        $this->deleteJson('/api/projects/alice/domains/shop.test')
            ->assertStatus(422)
            ->assertJsonPath('errors.domain.0', 'Cannot delete domain with existing subdomains');
        $this->assertSame(2, Domain::query()->count());
    }

    private function createDomain(User $user, string $name, string $type, ?string $parent): void
    {
        Domain::create([
            'user_id' => $user->id,
            'domain' => $name,
            'type' => $type,
            'details' => NewDomain::details($name, false, [], $parent),
        ]);
    }
}
