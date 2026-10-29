<?php

namespace Tests\Unit\Project;

use App\Exceptions\ProblemException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Project\ProjectCreator;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Support\FakeDomainAllocator;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A create buys its panelalpha.online name before the account exists. One
 * refused after that gives the name back to the proxy, since no tunnel row
 * is left that a later delete could release it from.
 */
class CreateReleasesOnlineNameTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->app->instance(DomainAllocator::class, new FakeDomainAllocator);
        Setting::setRuntimeSettings(['license_key' => 'K']);
        config(['connect.url' => 'https://connect.panelalpha.com']);
        Http::fake(['connect.panelalpha.com/*' => Http::response(null, 204)]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_create_refused_after_its_name_was_bought_gives_the_name_back(): void
    {
        $holder = $this->makeUser('holder', [], 'holder.example.com');
        $this->makeMainDomain($holder, 'shop-9f0e.panelalpha.online');

        try {
            $this->saveUnderAllocatedDomain(['username' => 'shop', 'email' => 'shop@example.com']);
            $this->fail('Expected the create to be refused.');
        } catch (ProblemException $e) {
            $this->assertSame('domain_taken', $e->problems[0]['code']);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://connect.panelalpha.com/api/without-dns/sites/shop-9f0e.panelalpha.online');
        $this->assertFalse(User::existsByUsername('shop'));
    }

    /** @param array<string, mixed> $params */
    private function saveUnderAllocatedDomain(array $params): User
    {
        /** @var User */
        return (new ReflectionMethod(ProjectCreator::class, 'saveUnderAllocatedDomain'))->invoke(null, $params, 'username', null);
    }
}
