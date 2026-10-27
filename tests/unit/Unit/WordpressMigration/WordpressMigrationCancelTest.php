<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\Tunnel;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationCancellation;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateDatabaseStep;
use Illuminate\Support\Facades\Queue;

/**
 * Cancel stops the run between steps and batches and removes what the import
 * made; DELETE removes a record whose run has ended.
 */
class WordpressMigrationCancelTest extends ImportTargetTestCase
{
    private const TOKEN = 'a3f1c2d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private RecordingSiteRemover $remover;

    /** @var list<string> */
    private array $closedPreviews = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->remover = new RecordingSiteRemover();
    }

    public function test_cancel_deletes_the_created_project_closes_the_preview_and_clears_the_secrets(): void
    {
        $domain = $this->project('wpcom', 'wp.com');
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $domain->user_id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'wp-com.panelalpha.online',
            'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW],
        ]);
        $migration = $this->started(MigrationTarget::newProject('wp.com', null));
        $migration->storeTargetProject('wpcom');
        $migration->storePreviewHostname('wp-com.panelalpha.online');
        $migration->storeMigratorToken(self::TOKEN);
        $migration->markStepCompleted(WordpressMigration::STEP_RESOLVE_SOURCE);
        $migration->markRunning();
        $migration->requestCancel();

        $this->advanceOnce($migration, new RoutedSourceHttpClient());

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->status);
        $this->assertSame([['deleteProject', 'wpcom']], $this->remover->calls);
        $this->assertSame(['wp-com.panelalpha.online'], $this->closedPreviews);
        $this->assertNull($migration->preview_hostname);
        $this->assertSame(['password' => null, 'migrator_token' => null, 'source_token' => null], $migration->secretsForWorker());
    }

    public function test_cancel_in_an_existing_project_removes_the_imported_files_and_database_only(): void
    {
        $this->project('shop', 'shop.example.com');
        $migration = $this->started(MigrationTarget::existingProject('shop', 'shop.example.com'), 'https://wp.com/blog');
        $migration->markStepCompleted(WordpressMigration::STEP_CREATE_PROJECT);
        $migration->markStepFailed(WordpressMigration::STEP_INSTALL_WORDPRESS);
        $migration->storeCreatedDatabase('shop_wp1a2b', 'shop_wp1a2b');
        $migration->requestCancel();

        $this->advanceOnce($migration, new RoutedSourceHttpClient());

        $home = rtrim($this->user('shop')->project()->homeDirPath(), '/');
        $this->assertSame([
            ['removeFiles', 'shop', $home . '/shop.example.com/public_html/blog'],
            ['dropDatabase', 'shop', 'shop_wp1a2b', 'shop_wp1a2b'],
        ], $this->remover->calls);
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->fresh()->status);
    }

    public function test_cancel_before_anything_was_made_only_ends_the_record(): void
    {
        $this->project('shop', 'shop.example.com');
        $migration = $this->started(MigrationTarget::existingProject('shop', 'shop.example.com'));
        $migration->requestCancel();

        $this->advanceOnce($migration, new RoutedSourceHttpClient());

        $this->assertSame([], $this->remover->calls);
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->fresh()->status);
    }

    public function test_a_manual_plugin_cancel_stops_the_transfer_with_the_plugin_token(): void
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            target: MigrationTarget::newProject('example.com', null),
            method: WordpressMigration::METHOD_MANUAL_PLUGIN,
            sourceToken: self::TOKEN,
        );
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);
        $migration->storePluginMigrationId('mig-7');
        $migration->requestCancel();
        $http = (new RoutedSourceHttpClient())
            ->on('POST', 'https://example.com/wp-json/wp-migrator/v1/backup/stop', new SourceHttpResponse('{"success":true}', 200));

        $this->advanceOnce($migration, $http);

        $stop = end($http->calls);
        $this->assertSame('https://example.com/wp-json/wp-migrator/v1/backup/stop', $stop['url']);
        $this->assertSame(self::TOKEN, $stop['headers']['X-Migrator-Token']);
        $this->assertSame(['migration_id' => 'mig-7'], json_decode((string) $stop['body'], true));
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->fresh()->status);
    }

    public function test_an_unreachable_source_is_noted_and_the_cancel_still_ends(): void
    {
        $migration = $this->started(MigrationTarget::newProject('example.com', null), 'https://example.com');
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);
        $migration->markStepFailed(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN);
        $migration->requestCancel();

        $this->advanceOnce($migration, new RoutedSourceHttpClient());

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->status);
        $notes = array_column(array_filter($migration->step_log, static fn (array $entry): bool => $entry['step'] === MigrationCancellation::LOG_STEP), 'message');
        $this->assertStringContainsString('Could not remove the migrator plugin from the source site', (string) ($notes[0] ?? ''));
    }

    public function test_a_running_batch_step_stops_at_the_next_batch_and_the_next_run_cancels(): void
    {
        $migration = $this->started(MigrationTarget::newProject('example.com', null), 'https://example.com');
        foreach ($migration->steps as $step) {
            if ($step['name'] === WordpressMigration::STEP_MIGRATE_DATABASE) {
                break;
            }
            $migration->markStepCompleted($step['name']);
        }
        $migration->storePluginMigrationId('mig-db');
        $migration->storeSourceToken(str_repeat('cd', 32));
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);
        $batches = 0;
        $http = (new RoutedSourceHttpClient())
            ->requiringToken(str_repeat('cd', 32))
            ->on('GET', 'https://example.com/wp-json/wp-migrator/v1/remote-sync/status/mig-db', new SourceHttpResponse('{"success":true,"status":{"status":"running","phase":"database"}}', 200))
            ->on('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch', function () use (&$batches, $migration): SourceHttpResponse {
                $batches++;
                $migration->requestCancel();

                return new SourceHttpResponse('{"finalized":false,"phase":"database","progress":{"percentage":10}}', 200);
            });

        $this->assertSame(MigrationSlice::More, $this->advanceOnce($migration, $http));
        $this->assertSame(1, $batches);
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $migration->fresh()->status);

        $this->advanceOnce($migration->fresh(), $http);

        $this->assertSame(1, $batches);
        $this->assertSame(WordpressMigration::STATUS_CANCELLED, $migration->fresh()->status);
    }

    public function test_cancel_route_answers_202_queues_the_cancel_and_refuses_an_ended_record(): void
    {
        Queue::fake();
        $migration = $this->started(MigrationTarget::newProject('example.com', null));

        $this->postJson('/api/wordpress/migrations/' . $migration->id . '/cancel')
            ->assertStatus(202)
            ->assertJsonPath('status', WordpressMigration::STATUS_CANCELLING);
        Queue::assertPushed(MigrateWordpress::class, 1);

        $migration->markCancelled();
        $this->postJson('/api/wordpress/migrations/' . $migration->id . '/cancel')->assertStatus(409);
        $this->postJson('/api/wordpress/migrations/999999/cancel')->assertStatus(404);
    }

    public function test_delete_route_removes_only_an_ended_record(): void
    {
        $running = $this->started(MigrationTarget::newProject('example.com', null));
        $running->markRunning();
        $done = $this->started(MigrationTarget::newProject('done.example.com', null));
        $done->markRunning();
        $done->markCompleted();

        $this->deleteJson('/api/wordpress/migrations/' . $running->id)->assertStatus(409);
        $this->deleteJson('/api/wordpress/migrations/' . $done->id)->assertNoContent();
        $this->deleteJson('/api/wordpress/migrations/' . $done->id)->assertStatus(404);

        $this->assertNotNull($running->fresh());
        $this->assertNull($done->fresh());
    }

    public function test_cancel_and_delete_commands_follow_the_api(): void
    {
        Queue::fake();
        $migration = $this->started(MigrationTarget::newProject('example.com', null));

        $this->artisan('wordpress:migrate:delete', ['id' => (string) $migration->id])->assertExitCode(1);
        $this->artisan('wordpress:migrate:cancel', ['id' => (string) $migration->id])->expectsOutput((string) $migration->id)->assertExitCode(0);
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $migration->fresh()->status);
        Queue::assertPushed(MigrateWordpress::class, 1);

        $migration->markCancelled();
        $this->artisan('wordpress:migrate:cancel', ['id' => (string) $migration->id])->assertExitCode(1);
        $this->artisan('wordpress:migrate:delete', ['id' => (string) $migration->id])->assertExitCode(0);
        $this->assertNull($migration->fresh());
    }

    public function test_a_cancel_that_finds_the_record_held_waits_and_asks_again(): void
    {
        $migration = $this->started(MigrationTarget::newProject('example.com', null));
        $migration->claimRun('live-run');
        $migration->requestCancel();
        $job = (new MigrateWordpress($migration->id))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(MigrateWordpress::BUSY_RETRY_SECONDS);
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $migration->fresh()->status);
    }

    private function started(MigrationTarget $target, string $url = 'https://wp.com'): WordpressMigration
    {
        return WordpressMigration::start($url, 'admin', 'secret', $target);
    }

    private function advanceOnce(WordpressMigration $migration, RoutedSourceHttpClient $http): MigrationSlice
    {
        $source = new SourceConnection(fn () => $http);
        $preview = new PreviewTunnel(
            remove: function (User $user, Tunnel $tunnel): void {
                $this->closedPreviews[] = $tunnel->hostname;
            },
        );
        $module = new WordPressMigrationModule(
            $this->hermeticSequence([
                WordpressMigration::STEP_MIGRATE_DATABASE => new MigrateDatabaseStep($source),
            ]),
            new NoFailureCleanup(),
            new MigrationCancellation($source, $preview, $this->remover),
        );

        return $module->advanceOnce($migration);
    }

    /**
     * @param array<string, MigrationStep> $replacements
     * @return list<MigrationStep>
     */
    private function hermeticSequence(array $replacements = []): array
    {
        $steps = [];
        foreach (WordPressMigrationModule::stepNames() as $name) {
            $steps[] = $replacements[$name] ?? new NoOpStep($name);
        }

        return $steps;
    }
}
