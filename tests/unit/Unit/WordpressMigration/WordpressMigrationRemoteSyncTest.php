<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use RuntimeException;

/**
 * remote-sync completes the step only when the plugin reports success=true
 * and a migration id. success=false must not store that id. The source site is
 * read back from the record, with no wp-admin login, the call carries the
 * source token, and a transfer holding the source's lock is taken over only
 * when this engine started it.
 */
class WordpressMigrationRemoteSyncTest extends WordpressMigrationTestCase
{
    private const REMOTE_SYNC = 'https://example.com/wp-json/wp-migrator/v1/remote-sync';

    private const REMOTE_SYNC_QUERY = 'https://example.com?rest_route=/wp-migrator/v1/remote-sync';

    public function test_success_false_does_not_complete_the_step(): void
    {
        $http = $this->plugin(new SourceHttpResponse(
            json_encode([
                'success' => false,
                'migration_id' => 'mig-should-not-stick',
                'message' => 'destination refused the token',
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $migration = $this->migrationReadyForRemoteSync();

        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
            $this->fail('Expected remote-sync failure');
        } catch (RuntimeException $e) {
            $this->assertSame('destination refused the token', $e->getMessage());
        }

        $migration->refresh();
        $step = collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_REMOTE_SYNC);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $step['status']);
        $this->assertNull($migration->plugin_migration_id);
    }

    public function test_success_true_stores_the_plugin_migration_id(): void
    {
        $http = $this->plugin(new SourceHttpResponse(
            json_encode([
                'success' => true,
                'migration_id' => 'mig-ok',
            ], JSON_THROW_ON_ERROR),
            200,
        ));

        $migration = $this->migrationReadyForRemoteSync();
        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);

        $migration->refresh();
        $step = collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_REMOTE_SYNC);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $step['status']);
        $this->assertSame('mig-ok', $migration->plugin_migration_id);
        $this->assertCount(1, $http->calls);
        $this->assertSame(self::REMOTE_SYNC, $http->calls[0]['url']);
        $this->assertSame(self::SOURCE_TOKEN, $http->calls[0]['headers']['X-Migrator-Token'] ?? null);
        $this->assertFalse(json_decode((string) $http->calls[0]['body'], true)['force']);
    }

    public function test_a_transfer_this_engine_started_is_taken_over_on_a_resume(): void
    {
        $migration = $this->migrationReadyForRemoteSync();
        $migration->storePluginMigrationId('mig_own');
        $migration->restartSteps([WordpressMigration::STEP_REMOTE_SYNC]);
        $http = $this->lockedBy('mig_own');

        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration->fresh());

        $this->assertSame('mig_new', $migration->fresh()->plugin_migration_id);
        $this->assertSame([false, true], $this->forceFlags($http));
    }

    public function test_the_lock_is_read_in_the_rest_mode_that_reaches_the_plugin(): void
    {
        $migration = $this->migrationReadyForRemoteSync();
        $migration->storePluginMigrationId('mig_own');
        $http = $this->lockedBy('mig_own', self::REMOTE_SYNC_QUERY);

        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration->fresh());

        $this->assertSame('mig_new', $migration->fresh()->plugin_migration_id);
        $this->assertSame([self::REMOTE_SYNC, self::REMOTE_SYNC_QUERY, self::REMOTE_SYNC_QUERY], array_column($http->calls, 'url'));
        $this->assertSame([false, false, true], $this->forceFlags($http));
    }

    public function test_a_transfer_this_engine_did_not_start_is_never_taken_over(): void
    {
        $migration = $this->migrationReadyForRemoteSync();
        $http = $this->lockedBy('mig_20261027_090000_someoneelse');

        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
            $this->fail('Expected the step to leave a foreign transfer alone');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not start (migration mig_20261027_090000_someoneelse)', $e->getMessage());
        }

        $this->assertNotContains(true, $this->forceFlags($http));
        $this->assertNull($migration->fresh()->plugin_migration_id);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOTE_SYNC));
    }

    public function test_a_record_without_a_source_token_starts_no_transfer(): void
    {
        $http = $this->plugin(new SourceHttpResponse('{"success":true,"migration_id":"mig-ok"}', 200));
        $migration = $this->migrationReadyForRemoteSync();
        $migration->clearSecrets();
        $migration->storeMigratorToken('tok-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source token');

        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration->fresh());
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    public function test_the_kept_domain_is_sent_with_the_token_and_pinned_to_the_engine(): void
    {
        $http = $this->plugin(
            new SourceHttpResponse(json_encode(['success' => true, 'migration_id' => 'mig-ok'], JSON_THROW_ON_ERROR), 200),
            'https://www.example.com/blog',
        );
        $token = str_repeat('cd', 32);
        $migration = WordpressMigration::start(
            sourceUrl: 'https://www.example.com/blog',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('example.com', null),
        );
        $migration->storeMigratorToken($token);
        $migration->storeSourceToken(self::SOURCE_TOKEN);
        $migration->storeResolvedSource('https://www.example.com/blog', null, SourceSite::REST_WP_JSON);

        (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration->fresh());

        $body = json_decode((string) end($http->calls)['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://example.com/blog?token=' . $token, $body['destination_url']);
        $this->assertSame(self::engineIpv4(), $body['ip']);
        $this->assertTrue($body['insecure_tls']);
        $this->assertStringNotContainsString($token, json_encode($migration->fresh()->step_log, JSON_THROW_ON_ERROR));
    }

    private function migrationReadyForRemoteSync(): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject('examplecom-ab.panelalpha.online', null),
        );
        $migration->storeMigratorToken('tok-1');
        $migration->storeSourceToken(self::SOURCE_TOKEN);
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }

    /**
     * A source whose lock this transfer holds, reached only at $url; only a
     * forced start takes it over. The other REST mode is not the plugin.
     */
    private function lockedBy(string $holder, string $url = self::REMOTE_SYNC): RoutedSourceHttpClient
    {
        $answer = static fn (array $headers, ?string $body): SourceHttpResponse => (json_decode((string) $body, true)['force'] ?? null) === true
            ? new SourceHttpResponse('{"success":true,"migration_id":"mig_new"}', 200)
            : new SourceHttpResponse(json_encode([
                'code' => 'sync_failed',
                'message' => 'Another migration is already in progress. Migration ID: ' . $holder . '. Please wait for it to complete or cancel it first.',
            ], JSON_THROW_ON_ERROR), 500);

        return (new RoutedSourceHttpClient())
            ->requiringToken(self::SOURCE_TOKEN)
            ->on('POST', $url, $answer);
    }

    /**
     * @return list<mixed>
     */
    private function forceFlags(RoutedSourceHttpClient $http): array
    {
        return array_map(
            static fn (array $call): mixed => json_decode((string) $call['body'], true)['force'] ?? null,
            $http->calls,
        );
    }

    private function plugin(SourceHttpResponse $remoteSync, string $home = 'https://example.com'): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->requiringToken(self::SOURCE_TOKEN)
            ->on('POST', $home . '/wp-json/wp-migrator/v1/remote-sync', $remoteSync);
    }
}
