<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;

/**
 * wordpress:migrate with no password exits non-zero and does not create a record.
 * The password is never a positional argument; the test calls the command in-process.
 */
class WordpressMigrationArtisanTest extends WordpressMigrationTestCase
{
    public function test_migrate_without_password_fails_and_creates_no_record(): void
    {
        putenv('WORDPRESS_MIGRATE_PASSWORD');
        unset($_ENV['WORDPRESS_MIGRATE_PASSWORD'], $_SERVER['WORDPRESS_MIGRATE_PASSWORD']);

        $this->artisan('wordpress:migrate', [
            'url' => 'https://example.com/blog',
            'username' => 'admin',
        ])->assertFailed();

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_manual_plugin_needs_a_token_instead_of_a_password(): void
    {
        putenv('WORDPRESS_MIGRATE_SOURCE_TOKEN');

        $this->artisan('wordpress:migrate', [
            'url' => 'https://93.184.216.34/blog',
            '--method' => 'manual_plugin',
        ])->assertFailed();
        $this->assertSame(0, WordpressMigration::query()->count());

        Queue::fake();
        putenv('WORDPRESS_MIGRATE_SOURCE_TOKEN=' . str_repeat('c4', 32));
        try {
            $this->artisan('wordpress:migrate', [
                'url' => 'https://93.184.216.34/blog',
                '--method' => 'manual_plugin',
            ])->assertSuccessful();
        } finally {
            putenv('WORDPRESS_MIGRATE_SOURCE_TOKEN');
        }

        $migration = WordpressMigration::query()->sole();
        $this->assertSame(WordpressMigration::METHOD_MANUAL_PLUGIN, $migration->method);
        $this->assertNull($migration->username);
        $this->assertSame(str_repeat('c4', 32), $migration->secretsForWorker()['source_token']);
        Queue::assertPushed(MigrateWordpress::class, 1);
    }
}
