<?php

namespace Tests\Unit\WordpressMigration;

use App\Console\Kernel;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;

/**
 * An unfinished import idle for SECRETS_KEPT_DAYS loses its wp-admin password
 * and tokens; resuming it asks for the source's secret again, and a transfer
 * it left unfinished starts again from the destination plugin's install.
 */
class WordpressMigrationSecretExpiryTest extends WordpressMigrationTestCase
{
    private const DESTINATION_TOKEN = 'aa11bb22cc33dd44ee55ff6600112233445566778899aabbccddeeff00112233';

    private const MANUAL_PLUGIN_TOKEN = 'c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4';

    public function test_a_failed_import_idle_for_the_bound_loses_every_secret_and_says_so(): void
    {
        $migration = $this->failedImport(idleDays: WordpressMigration::SECRETS_KEPT_DAYS + 1);

        $this->assertSame(1, WordpressMigration::expireIdleSecrets());

        $migration->refresh();
        $this->assertSame(['password' => null, 'migrator_token' => null, 'source_token' => null], $migration->secretsForWorker());
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->status);
        $last = $migration->step_log[array_key_last($migration->step_log)];
        $this->assertSame(WordpressMigration::LOG_SECRETS_EXPIRED, $last['step']);
        $this->assertStringContainsString('wp-admin password', $last['message']);
        $this->assertSame(0, WordpressMigration::expireIdleSecrets());
    }

    public function test_recent_and_finished_imports_keep_what_they_hold(): void
    {
        $recent = $this->failedImport(idleDays: WordpressMigration::SECRETS_KEPT_DAYS - 1);
        $completed = $this->failedImport(idleDays: 30);
        DB::table('wordpress_migrations')->where('id', $completed->id)->update(['status' => WordpressMigration::STATUS_COMPLETED]);

        $this->assertSame(0, WordpressMigration::expireIdleSecrets());

        $this->assertSame('secret', $recent->refresh()->secretsForWorker()['password']);
        $this->assertSame('secret', $completed->refresh()->secretsForWorker()['password']);
    }

    public function test_dead_runs_and_stuck_cancels_lose_their_secrets_too(): void
    {
        $dead = $this->failedImport(idleDays: 10, status: WordpressMigration::STATUS_RUNNING);
        $cancelling = $this->failedImport(idleDays: 10, status: WordpressMigration::STATUS_CANCELLING);

        $this->assertSame(2, WordpressMigration::expireIdleSecrets());

        $this->assertNull($dead->refresh()->secretsForWorker()['password']);
        $this->assertNull($cancelling->refresh()->secretsForWorker()['password']);
    }

    public function test_resume_refuses_without_the_cleared_password_and_restarts_the_unfinished_transfer_with_it(): void
    {
        $migration = $this->failedImport(idleDays: 10);
        WordpressMigration::expireIdleSecrets();
        $migration->refresh();

        $this->assertSame('password', $migration->missingSourceSecret());
        try {
            $migration->resume();
            $this->fail('Expected the resume to ask for the password');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('password was cleared', $e->getMessage());
        }
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->refresh()->status);

        $migration->resume('new-password');

        $this->assertSame(WordpressMigration::STATUS_PENDING, $migration->status);
        $this->assertSame('new-password', $migration->refresh()->secretsForWorker()['password']);
        $this->assertNull($migration->missingSourceSecret());
        $this->assertSame([
            WordpressMigration::STEP_RESOLVE_SOURCE => WordpressMigration::STEP_STATUS_COMPLETED,
            WordpressMigration::STEP_CREATE_PROJECT => WordpressMigration::STEP_STATUS_COMPLETED,
            WordpressMigration::STEP_INSTALL_WORDPRESS => WordpressMigration::STEP_STATUS_COMPLETED,
            WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_REMOTE_SYNC => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_MIGRATE_DATABASE => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_MIGRATE_FILES => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_FINALIZE => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_REMOVE_PLUGINS => WordpressMigration::STEP_STATUS_PENDING,
            WordpressMigration::STEP_CLEAR_SECRETS => WordpressMigration::STEP_STATUS_PENDING,
        ], array_column($migration->steps, 'status', 'name'));
    }

    public function test_a_finished_transfer_is_not_sent_again_when_the_password_comes_back(): void
    {
        $migration = $this->failedImport(idleDays: 10);
        $migration->markStepCompleted(WordpressMigration::STEP_MIGRATE_FILES);
        $migration->markStepCompleted(WordpressMigration::STEP_FINALIZE);
        $migration->markStepFailed(WordpressMigration::STEP_REMOVE_PLUGINS);
        DB::table('wordpress_migrations')->where('id', $migration->id)->update(['updated_at' => now()->subDays(10)]);
        WordpressMigration::expireIdleSecrets();

        $migration->refresh()->resume('new-password');

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->stepStatus(WordpressMigration::STEP_FINALIZE));
        $this->assertSame(WordpressMigration::STEP_STATUS_FAILED, $migration->stepStatus(WordpressMigration::STEP_REMOVE_PLUGINS));
    }

    public function test_a_resume_with_its_secrets_kept_continues_from_the_failed_step(): void
    {
        $migration = $this->failedImport(idleDays: 1);

        $migration->resume();

        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $migration->stepStatus(WordpressMigration::STEP_MIGRATE_DATABASE));
        $this->assertSame(WordpressMigration::STEP_STATUS_FAILED, $migration->stepStatus(WordpressMigration::STEP_MIGRATE_FILES));
        $this->assertSame(self::DESTINATION_TOKEN, $migration->secretsForWorker()['migrator_token']);
    }

    public function test_the_api_asks_for_the_cleared_secret_of_the_method(): void
    {
        $this->withoutMiddleware();
        Queue::fake();
        $wpAdmin = $this->failedImport(idleDays: 10);
        $manual = $this->failedImport(idleDays: 10, method: WordpressMigration::METHOD_MANUAL_PLUGIN);
        WordpressMigration::expireIdleSecrets();

        $this->postJson("/api/wordpress/migrations/{$wpAdmin->id}/resume")
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The wp-admin password was cleared after 7 days without activity: send it again as password.');
        $this->postJson("/api/wordpress/migrations/{$manual->id}/resume")->assertStatus(422)->assertJsonValidationErrors('source_token');
        $this->postJson("/api/wordpress/migrations/{$manual->id}/resume", ['source_token' => 'not-a-token'])->assertStatus(422);
        Queue::assertNothingPushed();

        $this->postJson("/api/wordpress/migrations/{$wpAdmin->id}/resume", ['password' => 'again'])->assertStatus(202);
        $this->postJson("/api/wordpress/migrations/{$manual->id}/resume", ['source_token' => self::MANUAL_PLUGIN_TOKEN])->assertStatus(202);

        Queue::assertPushed(MigrateWordpress::class, 2);
        $this->assertSame('again', $wpAdmin->refresh()->secretsForWorker()['password']);
        $this->assertSame(self::MANUAL_PLUGIN_TOKEN, $manual->refresh()->secretsForWorker()['source_token']);
    }

    public function test_the_cli_resume_reads_the_cleared_password_from_the_environment(): void
    {
        Queue::fake();
        $migration = $this->failedImport(idleDays: 10);
        WordpressMigration::expireIdleSecrets();

        putenv('WORDPRESS_MIGRATE_PASSWORD');
        $this->artisan('wordpress:migrate:resume', ['id' => $migration->id])->assertFailed();
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->refresh()->status);

        putenv('WORDPRESS_MIGRATE_PASSWORD=from-env');
        try {
            $this->artisan('wordpress:migrate:resume', ['id' => $migration->id])->assertSuccessful();
        } finally {
            putenv('WORDPRESS_MIGRATE_PASSWORD');
        }

        $this->assertSame('from-env', $migration->refresh()->secretsForWorker()['password']);
        Queue::assertPushed(MigrateWordpress::class, 1);
    }

    public function test_the_sweep_runs_hourly_and_reports_how_many_it_cleared(): void
    {
        $this->failedImport(idleDays: 10);

        $this->assertSame(0, Artisan::call('wordpress:migrate:expire-secrets'));
        $this->assertStringContainsString('1 cleared', Artisan::output());

        /** @var Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $events = array_values(array_filter(
            $kernel->resolveConsoleSchedule()->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'wordpress:migrate:expire-secrets'),
        ));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    /**
     * An import that failed in migrate_files after its destination plugin got
     * its token, last touched $idleDays ago.
     */
    private function failedImport(
        int $idleDays,
        string $status = WordpressMigration::STATUS_FAILED,
        string $method = WordpressMigration::METHOD_WP_ADMIN,
    ): WordpressMigration {
        $manual = $method === WordpressMigration::METHOD_MANUAL_PLUGIN;
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: $manual ? null : 'admin',
            password: $manual ? null : 'secret',
            method: $method,
            sourceToken: $manual ? self::MANUAL_PLUGIN_TOKEN : null,
        );
        $migration->storeMigratorToken(self::DESTINATION_TOKEN);
        foreach ($migration->steps as $step) {
            if ($step['name'] === WordpressMigration::STEP_MIGRATE_FILES) {
                break;
            }
            $migration->markStepCompleted($step['name']);
        }
        $migration->markStepFailed(WordpressMigration::STEP_MIGRATE_FILES);
        DB::table('wordpress_migrations')->where('id', $migration->id)->update([
            'status' => $status,
            'updated_at' => now()->subDays($idleDays),
        ]);

        return $migration->refresh();
    }
}
