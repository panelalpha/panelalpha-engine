<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;

/**
 * wordpress:migrate takes the same target as the API, refuses it the same
 * way, and wordpress:migrate:status prints where the site lands.
 */
class WordpressMigrationTargetArtisanTest extends ImportTargetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        putenv('WORDPRESS_MIGRATE_PASSWORD=secret');
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => ['93.184.216.34'],
        );
    }

    protected function tearDown(): void
    {
        putenv('WORDPRESS_MIGRATE_PASSWORD');
        parent::tearDown();
    }

    public function test_a_taken_domain_fails_and_stores_nothing(): void
    {
        $this->project('owner', 'example.com');

        $this->artisan('wordpress:migrate', ['url' => 'https://www.example.com', 'username' => 'admin'])
            ->expectsOutputToContain('already belongs to a project')
            ->assertFailed();

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_the_target_options_are_stored_and_printed(): void
    {
        $this->artisan('wordpress:migrate', [
            'url' => 'https://example.com',
            'username' => 'admin',
            '--domain' => 'new.example.org',
            '--project-name' => 'newsite',
        ])->assertSuccessful();

        $migration = WordpressMigration::query()->sole();
        $this->assertSame('new.example.org', $migration->target_label);

        $this->artisan('wordpress:migrate:status', ['id' => (string) $migration->id])
            ->expectsOutput('Project: newsite (created by the import)')
            ->expectsOutput('Domain: new.example.org')
            ->expectsOutput('DNS: A ' . self::ENGINE_IPV4)
            ->assertSuccessful();
    }
}
