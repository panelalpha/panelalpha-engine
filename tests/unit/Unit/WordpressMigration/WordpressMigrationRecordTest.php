<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class WordpressMigrationRecordTest extends WordpressMigrationTestCase
{
    public function test_schema_migration_creates_wordpress_migrations_table(): void
    {
        $this->assertTrue(Schema::hasTable('wordpress_migrations'));
        $this->assertTrue(Schema::hasColumns('wordpress_migrations', [
            'id',
            'source_url',
            'username',
            'target_label',
            'status',
            'steps',
            'step_log',
            'progress',
            'password',
            'migrator_token',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_record_survives_reload_with_steps_url_username_and_target_label(): void
    {
        Queue::fake();

        $created = WordpressMigration::start(
            sourceUrl: 'https://www.example.com/blog',
            username: 'wp-admin',
            password: 'secret-password',
            target: MigrationTarget::newProject('example-com-ab12.panelalpha.online', null),
        );

        $loaded = WordpressMigration::query()->findOrFail($created->id);

        $this->assertSame('https://www.example.com/blog', $loaded->source_url);
        $this->assertSame('wp-admin', $loaded->username);
        $this->assertSame('example-com-ab12.panelalpha.online', $loaded->target_label);
        $this->assertSame(WordpressMigration::STATUS_PENDING, $loaded->status);
        $this->assertCount(count(Migration::stepNames()), $loaded->steps);

        foreach (Migration::stepNames() as $i => $name) {
            $this->assertSame($name, $loaded->steps[$i]['name']);
            $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $loaded->steps[$i]['status']);
        }

        $this->assertSame('secret-password', $loaded->password);
    }

    public function test_optional_target_label_may_be_null(): void
    {
        Queue::fake();

        $created = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'pw',
        );

        $loaded = WordpressMigration::query()->findOrFail($created->id);
        $this->assertNull($loaded->target_label);
    }

    public function test_start_persists_the_record_without_queueing_a_job(): void
    {
        Queue::fake();

        $created = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'pw',
        );

        $this->assertSame(WordpressMigration::STATUS_PENDING, $created->fresh()->status);
        Queue::assertNotPushed(MigrateWordpress::class);
    }
}
