<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\CopyDomain;
use App\Lib\Domains\DomainAllocator;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDomainAllocator;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A staging copy or clone of a project on a free panelalpha.online name gets
 * a name of its own one label deep, never `staging.<label>`, which the
 * proxy's certificate does not cover. That name is bought only once the copy
 * is being created.
 */
class CopyDomainTest extends TestCase
{
    use InMemoryDatabase;

    private FakeDomainAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->allocator = new FakeDomainAllocator;
        $this->app->instance(DomainAllocator::class, $this->allocator);
        Setting::setRuntimeSettings(['license_key' => 'K']);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        Http::fake(['connect.panelalpha.com/*' => Http::response(null, 204)]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_copy_of_an_own_domain_is_named_staging_in_front_of_it(): void
    {
        $copy = CopyDomain::forCopy('shop.example.com', null);

        $this->assertSame('staging.shop.example.com', $copy?->claim('shopcopy'));
        $this->assertSame([], $this->allocator->asked);
    }

    public function test_a_requested_name_loses_its_www(): void
    {
        $this->assertSame('copy.example.com', CopyDomain::forCopy('shop-565d.panelalpha.online', 'www.copy.example.com')?->claim('shopcopy'));
        $this->assertSame([], $this->allocator->asked);
    }

    public function test_a_copy_of_a_panelalpha_online_name_buys_one_of_its_own_only_when_claimed(): void
    {
        $copy = CopyDomain::forCopy('shop-565d.panelalpha.online', null);

        $this->assertNotNull($copy);
        $this->assertFalse($copy->isTaken());
        $this->assertSame([], $this->allocator->asked);
        $this->assertSame('shop1234-9f0e.panelalpha.online', $copy->claim('shop1234'));
        $this->assertSame(['shop1234'], $this->allocator->asked);
    }

    public function test_the_copy_records_where_its_name_came_from_and_the_proxy_registration(): void
    {
        $live = $this->makeUser('shop', ['template' => 'dind'], 'shop-565d.panelalpha.online');

        $dest = $live->makePendingStaging('shop1234', CopyDomain::forCopy($live->domain, null));

        $this->assertSame('shop1234-9f0e.panelalpha.online', $dest->domain);
        $this->assertSame('panelalpha_online', $dest->refresh()->getDetails()['domain']['source'] ?? null);
        $this->assertSame('proxy', $dest->getDetails()['domain']['tls_terminated_at'] ?? null);
        $domain = Domain::query()->where('domain', 'shop1234-9f0e.panelalpha.online')->sole();
        $this->assertSame([], $domain->getAliases());
        $tunnel = Tunnel::query()->where('user_id', $dest->id)->sole();
        $this->assertSame('shop1234-9f0e.panelalpha.online', $tunnel->hostname);
        $this->assertSame(Tunnel::PROVIDER_PANELALPHA, $tunnel->provider);
        $this->assertSame($domain->id, $tunnel->domain_id);
        Http::assertNothingSent();
    }

    public function test_a_copy_that_cannot_be_saved_gives_its_bought_name_back(): void
    {
        $live = $this->makeUser('shop', ['template' => 'dind'], 'shop-565d.panelalpha.online');
        $this->makeUser('shop1234', [], 'taken.example.com');

        try {
            $live->makePendingStaging('shop1234', CopyDomain::forCopy($live->domain, null));
            $this->fail('Expected the copy to collide with the existing account.');
        } catch (UniqueConstraintViolationException) {
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/shop1234-9f0e.panelalpha.online');
        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_a_failed_copy_of_an_own_domain_has_nothing_to_give_back(): void
    {
        $live = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.com');
        $this->makeUser('shopcopy', [], 'taken.example.com');

        try {
            $live->makeClone('shopcopy', CopyDomain::forCopy($live->domain, null));
            $this->fail('Expected the clone to collide with the existing account.');
        } catch (UniqueConstraintViolationException) {
        }

        Http::assertNothingSent();
    }

    public function test_a_clone_of_an_own_domain_records_nothing(): void
    {
        $live = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.com');

        $clone = $live->makeClone('shopcopy', CopyDomain::forCopy($live->domain, null));

        $this->assertSame('staging.shop.example.com', $clone->domain);
        $this->assertArrayNotHasKey('domain', $clone->refresh()->getDetails());
        $this->assertSame(['www.staging.shop.example.com'], Domain::query()->where('user_id', $clone->id)->sole()->getAliases());
        $this->assertSame(0, Tunnel::query()->count());
        $this->assertSame([], $this->allocator->asked);
    }
}
