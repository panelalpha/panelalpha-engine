<?php

namespace Tests\Integration\Console\Wordpress;

use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;
use Tests\Integration\WordpressMigration\ImportTargetTestCase;
use Tests\Support\TestNetwork;

/** `wordpress:migrate` prints every validation error of its input and stores no migration. */
class WordpressMigrateValidationTest extends ImportTargetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        putenv('WORDPRESS_MIGRATE_PASSWORD=secret');
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => [TestNetwork::routable(34)],
        );
    }

    protected function tearDown(): void
    {
        putenv('WORDPRESS_MIGRATE_PASSWORD');
        parent::tearDown();
    }

    public function test_an_unknown_method_and_a_malformed_url_are_both_reported(): void
    {
        $this->artisan('wordpress:migrate', ['url' => 'not a url', 'username' => 'admin', '--method' => 'carrier_pigeon'])
            ->expectsOutput('Source WordPress URL must use http or https')
            ->expectsOutput('The selected method is invalid.')
            ->assertFailed();

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_the_manual_plugin_method_needs_its_token(): void
    {
        $this->artisan('wordpress:migrate', ['url' => 'https://example.com', '--method' => 'manual_plugin', '--no-interaction' => true])
            ->expectsOutput('Source token required: set WORDPRESS_MIGRATE_SOURCE_TOKEN or run interactively.')
            ->assertFailed();
    }
}
