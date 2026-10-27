<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\DomainAllocator;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDomainAllocator;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A panelalpha.online name for a copy is bought at the proxy, out of a site
 * quota the whole engine shares. A copy the engine refuses must not have
 * bought one.
 */
class CopyNameAllocationHttpTest extends TestCase
{
    use InMemoryDatabase;

    private FakeDomainAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        $this->allocator = new FakeDomainAllocator;
        $this->app->instance(DomainAllocator::class, $this->allocator);
    }

    public function test_a_refused_staging_copy_buys_no_name(): void
    {
        $source = $this->sourceWhoseCopyIsRefused();

        $response = $this->postJson("/api/projects/{$source->username}/staging", []);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'database_user_name_too_long');
        $this->assertSame([], $this->allocator->asked);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_refused_clone_buys_no_name(): void
    {
        $source = $this->sourceWhoseCopyIsRefused();

        $response = $this->postJson("/api/projects/{$source->username}/clone", []);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'database_user_name_too_long');
        $this->assertSame([], $this->allocator->asked);
        $this->assertSame(1, User::query()->count());
    }

    /** A source on a panelalpha.online name whose MySQL user is too long under any generated copy name. */
    private function sourceWhoseCopyIsRefused(): User
    {
        $source = $this->makeUser('shopx', ['template' => 'dind', 'mysql_prefix' => 'shopx_'], 'shopx-565d.panelalpha.online');
        $this->makeMainDomain($source, 'shopx-565d.panelalpha.online');
        $source->mysqlUsers()->create(['user' => 'shopx_'.str_repeat('u', 26)]);

        return $source->refresh();
    }
}
