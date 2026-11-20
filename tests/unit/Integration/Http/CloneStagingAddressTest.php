<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\CopyProject;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A copy of a live project takes a name of its own, so the `staging.` address
 * the staging flow starts from is still free once the copy exists.
 */
class CloneStagingAddressTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        LocalRoot::answerLocally();
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        parent::tearDown();
    }

    public function test_a_copy_leaves_the_address_its_sources_staging_copy_is_planned(): void
    {
        $live = $this->makeUser('shoplive', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($live, 'shop.example.test');

        $this->postJson('/api/projects', [
            'name' => 'shopclone',
            'source' => ['type' => 'project', 'from' => 'shoplive'],
        ])->assertStatus(202);

        $this->assertSame('clone.shop.example.test', User::findByUsername('shopclone')?->domain);
        Queue::assertPushed(CopyProject::class);

        $this->postJson('/api/projects/shoplive/staging', [
            'new_username' => 'shopstage',
            'domain' => 'staging.shop.example.test',
        ])->assertStatus(202);

        $this->assertSame('staging.shop.example.test', User::findByUsername('shopstage')?->domain);
    }
}
